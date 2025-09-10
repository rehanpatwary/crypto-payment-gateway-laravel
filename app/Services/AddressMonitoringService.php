<?php

namespace App\Services;

use App\Models\UserAddress;
use App\Models\UserTransaction;
use App\Models\AddressMonitoringJob;
use App\Services\Blockchain\BlockchainServiceFactory;
use App\Services\ExchangeRateService;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AddressMonitoringService
{
    protected $blockchainServiceFactory;
    protected $exchangeRateService;
    protected $webhookService;

    public function __construct(
        BlockchainServiceFactory $blockchainServiceFactory,
        ExchangeRateService $exchangeRateService,
        WebhookService $webhookService
    ) {
        $this->blockchainServiceFactory = $blockchainServiceFactory;
        $this->exchangeRateService = $exchangeRateService;
        $this->webhookService = $webhookService;
    }

    /**
     * Monitor all active addresses for new transactions
     */
    public function monitorAllAddresses(int $limit = 100): array
    {
        $results = [
            'checked' => 0,
            'new_transactions' => 0,
            'updated_transactions' => 0,
            'errors' => 0
        ];

        // Get monitoring jobs that need checking
        $monitoringJobs = AddressMonitoringJob::needsCheck(5) // 5 minutes interval
            ->with(['userAddress.cryptocurrency', 'userAddress.user'])
            ->limit($limit)
            ->get();

        foreach ($monitoringJobs as $job) {
            try {
                $this->monitorAddress($job);
                $results['checked']++;
            } catch (\Exception $e) {
                $results['errors']++;
                Log::error("Address monitoring failed", [
                    'address' => $job->address,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Check for transaction updates (confirmations)
        $updateResults = $this->updatePendingTransactions();
        $results['updated_transactions'] = $updateResults['updated'];
        $results['errors'] += $updateResults['errors'];

        return $results;
    }

    /**
     * Monitor specific address for new transactions
     */
    public function monitorAddress(AddressMonitoringJob $job): void
    {
        $userAddress = $job->userAddress;
        $cryptocurrency = $userAddress->cryptocurrency;
        
        try {
            $blockchainService = $this->blockchainServiceFactory->create($cryptocurrency->symbol);
            
            // Update balance
            $currentBalance = $blockchainService->getBalance($userAddress->address);
            if ($currentBalance != $userAddress->balance) {
                $userAddress->updateBalance($currentBalance);
            }

            // Get recent transactions
            $newTransactions = $this->getNewTransactionsForAddress($userAddress, $blockchainService);
            
            foreach ($newTransactions as $txData) {
                $this->processNewTransaction($userAddress, $txData);
            }

            // Mark job as checked
            $job->markAsChecked();

        } catch (\Exception $e) {
            Log::error("Failed to monitor address", [
                'address' => $userAddress->address,
                'cryptocurrency' => $cryptocurrency->symbol,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get new transactions for address
     */
    protected function getNewTransactionsForAddress(UserAddress $userAddress, $blockchainService): array
    {
        $newTransactions = [];
        
        try {
            // Get address transaction history
            if (method_exists($blockchainService, 'getAddressHistory')) {
                $history = $blockchainService->getAddressHistory($userAddress->address, 1, 25);
                $transactions = $history['transactions'] ?? [];
            } else {
                // Fallback to basic transaction detection
                $transactions = $this->getBasicTransactionHistory($blockchainService, $userAddress->address);
            }

            foreach ($transactions as $tx) {
                // Check if transaction already exists
                $existingTx = UserTransaction::where('txid', $tx['txid'] ?? $tx['hash'] ?? $tx['signature'])
                    ->where('user_address_id', $userAddress->id)
                    ->first();

                if (!$existingTx) {
                    // Check if this transaction sends funds to our address
                    if ($this->isIncomingTransaction($tx, $userAddress)) {
                        $newTransactions[] = $tx;
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error("Failed to get transactions for address", [
                'address' => $userAddress->address,
                'error' => $e->getMessage()
            ]);
        }

        return $newTransactions;
    }

    /**
     * Process new incoming transaction
     */
    protected function processNewTransaction(UserAddress $userAddress, array $txData): void
    {
        DB::beginTransaction();
        
        try {
            $cryptocurrency = $userAddress->cryptocurrency;
            $blockchainService = $this->blockchainServiceFactory->create($cryptocurrency->symbol);
            
            // Extract transaction data
            $txid = $txData['txid'] ?? $txData['hash'] ?? $txData['signature'];
            $amount = $this->extractAmount($txData, $userAddress);
            $fromAddress = $this->extractFromAddress($txData);
            
            // Get USD amount
            $rate = $this->exchangeRateService->getRate($cryptocurrency->symbol);
            $amountUsd = $amount * $rate;
            
            // Get confirmations
            $confirmations = $blockchainService->getConfirmations($txid);
            $requiredConfirmations = config("crypto.networks.{$cryptocurrency->symbol}.min_confirmations", 3);
            
            // Create transaction record
            $userTransaction = UserTransaction::create([
                'user_id' => $userAddress->user_id,
                'user_address_id' => $userAddress->id,
                'cryptocurrency_id' => $cryptocurrency->id,
                'txid' => $txid,
                'from_address' => $fromAddress,
                'to_address' => $userAddress->address,
                'amount' => $amount,
                'amount_usd' => $amountUsd,
                'confirmations' => $confirmations,
                'required_confirmations' => $requiredConfirmations,
                'status' => $confirmations >= $requiredConfirmations ? 'confirmed' : 'pending',
                'block_hash' => $txData['blockHash'] ?? null,
                'block_height' => $txData['blockHeight'] ?? null,
                'block_time' => isset($txData['blockTime']) ? \Carbon\Carbon::createFromTimestamp($txData['blockTime']) : null,
                'fee' => $txData['fees'] ?? null,
                'raw_transaction' => $txData
            ]);

            DB::commit();

            Log::info("New transaction detected", [
                'user_id' => $userAddress->user_id,
                'address' => $userAddress->address,
                'txid' => $txid,
                'amount' => $amount,
                'currency' => $cryptocurrency->symbol,
                'confirmations' => $confirmations
            ]);

            // Send webhook notification
            $this->sendTransactionWebhook($userTransaction, 'payment_received');

            // If already confirmed, send confirmation webhook too
            if ($userTransaction->status === 'confirmed') {
                $this->sendTransactionWebhook($userTransaction, 'payment_confirmed');
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to process new transaction", [
                'address' => $userAddress->address,
                'txData' => $txData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update pending transactions with new confirmation counts
     */
    protected function updatePendingTransactions(): array
    {
        $results = ['updated' => 0, 'errors' => 0];
        
        $pendingTransactions = UserTransaction::pending()
            ->with(['cryptocurrency', 'userAddress.user'])
            ->limit(50)
            ->get();

        foreach ($pendingTransactions as $transaction) {
            try {
                $blockchainService = $this->blockchainServiceFactory->create($transaction->cryptocurrency->symbol);
                $confirmations = $blockchainService->getConfirmations($transaction->txid);
                
                if ($confirmations != $transaction->confirmations) {
                    $oldConfirmations = $transaction->confirmations;
                    $transaction->updateConfirmations($confirmations);
                    
                    Log::info("Transaction confirmations updated", [
                        'txid' => $transaction->txid,
                        'old_confirmations' => $oldConfirmations,
                        'new_confirmations' => $confirmations
                    ]);

                    // If just became confirmed, send webhook
                    if ($transaction->status === 'confirmed' && $oldConfirmations < $transaction->required_confirmations) {
                        $this->sendTransactionWebhook($transaction, 'payment_confirmed');
                    }
                    
                    $results['updated']++;
                }
                
            } catch (\Exception $e) {
                $results['errors']++;
                Log::error("Failed to update transaction confirmations", [
                    'txid' => $transaction->txid,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * Check if transaction is incoming to our address
     */
    protected function isIncomingTransaction(array $tx, UserAddress $userAddress): bool
    {
        $cryptocurrency = $userAddress->cryptocurrency;
        
        // For UTXO-based cryptocurrencies (BTC, LTC)
        if (isset($tx['vout'])) {
            foreach ($tx['vout'] as $output) {
                if (isset($output['addresses']) && in_array($userAddress->address, $output['addresses'])) {
                    return true;
                }
                if (isset($output['scriptpubkey_address']) && $output['scriptpubkey_address'] === $userAddress->address) {
                    return true;
                }
            }
        }
        
        // For account-based cryptocurrencies (ETH, SOL)
        if (isset($tx['to']) && strtolower($tx['to']) === strtolower($userAddress->address)) {
            return true;
        }
        
        // For token transactions
        if ($cryptocurrency->is_token && isset($tx['tokenTransfers'])) {
            foreach ($tx['tokenTransfers'] as $transfer) {
                if (strtolower($transfer['to']) === strtolower($userAddress->address) &&
                    strtolower($transfer['contract']) === strtolower($cryptocurrency->contract_address)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Extract amount from transaction data
     */
    protected function extractAmount(array $tx, UserAddress $userAddress): float
    {
        $cryptocurrency = $userAddress->cryptocurrency;
        
        // For UTXO-based cryptocurrencies
        if (isset($tx['vout'])) {
            $totalAmount = 0;
            foreach ($tx['vout'] as $output) {
                if ((isset($output['addresses']) && in_array($userAddress->address, $output['addresses'])) ||
                    (isset($output['scriptpubkey_address']) && $output['scriptpubkey_address'] === $userAddress->address)) {
                    $totalAmount += $output['value'] / pow(10, $cryptocurrency->decimals);
                }
            }
            return $totalAmount;
        }
        
        // For account-based cryptocurrencies
        if (isset($tx['value'])) {
            return $tx['value'] / pow(10, $cryptocurrency->decimals);
        }
        
        // For tokens
        if ($cryptocurrency->is_token && isset($tx['tokenTransfers'])) {
            $totalAmount = 0;
            foreach ($tx['tokenTransfers'] as $transfer) {
                if (strtolower($transfer['to']) === strtolower($userAddress->address) &&
                    strtolower($transfer['contract']) === strtolower($cryptocurrency->contract_address)) {
                    $totalAmount += $transfer['value'] / pow(10, $cryptocurrency->decimals);
                }
            }
            return $totalAmount;
        }
        
        return 0;
    }

    /**
     * Extract from address from transaction data
     */
    protected function extractFromAddress(array $tx): ?string
    {
        // For UTXO-based cryptocurrencies
        if (isset($tx['vin'][0]['addresses'][0])) {
            return $tx['vin'][0]['addresses'][0];
        }
        
        // For account-based cryptocurrencies
        if (isset($tx['from'])) {
            return $tx['from'];
        }
        
        return null;
    }

    /**
     * Get basic transaction history fallback
     */
    protected function getBasicTransactionHistory($blockchainService, string $address): array
    {
        // This is a fallback method for services that don't have getAddressHistory
        // Try to use findTransactionByAddress with different amounts
        return [];
    }

    /**
     * Send webhook notification for transaction
     */
    protected function sendTransactionWebhook(UserTransaction $transaction, string $event): void
    {
        try {
            $user = $transaction->userAddress->user;
            
            if ($user->hasWebhookForEvent($event)) {
                $this->webhookService->sendTransactionWebhook($transaction, $event);
            }
        } catch (\Exception $e) {
            Log::error("Failed to send transaction webhook", [
                'transaction_id' => $transaction->id,
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Monitor specific user's addresses
     */
    public function monitorUserAddresses(int $userId): array
    {
        $results = ['checked' => 0, 'new_transactions' => 0, 'errors' => 0];
        
        $monitoringJobs = AddressMonitoringJob::whereHas('userAddress', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })->active()->get();
        
        foreach ($monitoringJobs as $job) {
            try {
                $this->monitorAddress($job);
                $results['checked']++;
            } catch (\Exception $e) {
                $results['errors']++;
            }
        }
        
        return $results;
    }
}