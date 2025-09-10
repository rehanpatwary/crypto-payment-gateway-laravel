<?php

namespace App\Services;

use App\Models\Cryptocurrency;
use App\Models\PaymentTransaction;
use App\Models\WalletAddress;
use App\Services\Blockchain\BlockchainServiceFactory;
use App\Services\ExchangeRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CryptoPaymentService
{
    protected $exchangeRateService;
    protected $blockchainServiceFactory;

    public function __construct(
        ExchangeRateService $exchangeRateService,
        BlockchainServiceFactory $blockchainServiceFactory
    ) {
        $this->exchangeRateService = $exchangeRateService;
        $this->blockchainServiceFactory = $blockchainServiceFactory;
    }

    /**
     * Create a new payment request
     */
    public function createPayment(array $data): PaymentTransaction
    {
        DB::beginTransaction();
        
        try {
            // Validate cryptocurrency
            $cryptocurrency = Cryptocurrency::active()
                ->where('symbol', strtoupper($data['currency']))
                ->firstOrFail();

            // Validate amount
            if (!$cryptocurrency->isValidAmount($data['amount'])) {
                throw new \InvalidArgumentException(
                    "Amount must be between {$cryptocurrency->min_amount} and {$cryptocurrency->max_amount}"
                );
            }

            // Get available wallet address
            $walletAddress = $cryptocurrency->getAvailableWalletAddress();
            if (!$walletAddress) {
                throw new \RuntimeException("No available wallet address for {$cryptocurrency->symbol}");
            }

            // Calculate USD amount if not provided
            $amountUsd = $data['amount_usd'] ?? null;
            if (!$amountUsd) {
                $rate = $this->exchangeRateService->getRate($cryptocurrency->symbol);
                $amountUsd = $data['amount'] * $rate;
            }

            // Create payment transaction
            $transaction = PaymentTransaction::create([
                'cryptocurrency_id' => $cryptocurrency->id,
                'wallet_address_id' => $walletAddress->id,
                'amount' => $data['amount'],
                'amount_usd' => $amountUsd,
                'to_address' => $walletAddress->address,
                'required_confirmations' => $data['required_confirmations'] ?? $this->getDefaultConfirmations($cryptocurrency->symbol),
                'expires_at' => now()->addMinutes($data['expires_in_minutes'] ?? 30),
                'callback_url' => $data['callback_url'] ?? null,
                'metadata' => $data['metadata'] ?? null
            ]);

            // Mark wallet address as used
            $walletAddress->markAsUsed();

            DB::commit();
            
            Log::info("Payment created", [
                'transaction_id' => $transaction->transaction_id,
                'currency' => $cryptocurrency->symbol,
                'amount' => $data['amount']
            ]);

            return $transaction;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create payment", [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    /**
     * Check payment status and update confirmations
     */
    public function checkPaymentStatus(PaymentTransaction $transaction): PaymentTransaction
    {
        try {
            $blockchainService = $this->blockchainServiceFactory->create($transaction->cryptocurrency->symbol);
            
            // Check if transaction exists on blockchain
            if (!$transaction->blockchain_tx_hash) {
                $txHash = $blockchainService->findTransactionByAddress(
                    $transaction->to_address,
                    $transaction->amount
                );
                
                if ($txHash) {
                    $transaction->update(['blockchain_tx_hash' => $txHash]);
                }
            }

            // Update confirmations if we have a transaction hash
            if ($transaction->blockchain_tx_hash) {
                $confirmations = $blockchainService->getConfirmations($transaction->blockchain_tx_hash);
                $transaction->update(['confirmations' => $confirmations]);

                // Update status based on confirmations
                if ($confirmations >= $transaction->required_confirmations && $transaction->status === 'pending') {
                    $transaction->markAsConfirmed();
                    $this->sendCallback($transaction);
                }
            }

            // Check if transaction is expired
            if ($transaction->isExpired() && $transaction->status === 'pending') {
                $transaction->update(['status' => 'expired']);
            }

        } catch (\Exception $e) {
            Log::error("Failed to check payment status", [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage()
            ]);
        }

        return $transaction->fresh();
    }

    /**
     * Get payment status
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $transaction = PaymentTransaction::where('transaction_id', $transactionId)->firstOrFail();
        
        // Check for updates
        $transaction = $this->checkPaymentStatus($transaction);

        return [
            'transaction_id' => $transaction->transaction_id,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'currency' => $transaction->cryptocurrency->symbol,
            'to_address' => $transaction->to_address,
            'confirmations' => $transaction->confirmations,
            'required_confirmations' => $transaction->required_confirmations,
            'blockchain_tx_hash' => $transaction->blockchain_tx_hash,
            'expires_at' => $transaction->expires_at->toISOString(),
            'qr_data' => $transaction->getQrCodeData()
        ];
    }

    /**
     * Get supported cryptocurrencies
     */
    public function getSupportedCurrencies(): array
    {
        return Cryptocurrency::active()
            ->get()
            ->map(function ($crypto) {
                return [
                    'symbol' => $crypto->symbol,
                    'name' => $crypto->name,
                    'min_amount' => $crypto->min_amount,
                    'max_amount' => $crypto->max_amount,
                    'fee_percentage' => $crypto->fee_percentage,
                    'fixed_fee' => $crypto->fixed_fee,
                    'is_token' => $crypto->is_token,
                    'contract_address' => $crypto->contract_address
                ];
            })
            ->toArray();
    }

    /**
     * Send callback notification
     */
    protected function sendCallback(PaymentTransaction $transaction): void
    {
        if (!$transaction->callback_url || $transaction->callback_sent) {
            return;
        }

        try {
            $payload = [
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->cryptocurrency->symbol,
                'blockchain_tx_hash' => $transaction->blockchain_tx_hash,
                'confirmations' => $transaction->confirmations
            ];

            // Send HTTP POST request to callback URL
            $response = \Http::post($transaction->callback_url, $payload);
            
            if ($response->successful()) {
                $transaction->update(['callback_sent' => true]);
                Log::info("Callback sent successfully", [
                    'transaction_id' => $transaction->transaction_id,
                    'callback_url' => $transaction->callback_url
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Failed to send callback", [
                'transaction_id' => $transaction->transaction_id,
                'callback_url' => $transaction->callback_url,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get default confirmations for cryptocurrency
     */
    protected function getDefaultConfirmations(string $symbol): int
    {
        $defaults = [
            'BTC' => 3,
            'LTC' => 6,
            'ETH' => 12,
            'SOL' => 32,
            'XMR' => 10
        ];

        return $defaults[strtoupper($symbol)] ?? 6;
    }
}