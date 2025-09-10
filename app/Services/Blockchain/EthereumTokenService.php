<?php

namespace App\Services\Blockchain;

use App\Models\Cryptocurrency;
use App\Services\Blockchain\Contracts\BlockchainServiceInterface;
use Illuminate\Support\Facades\Log;

class EthereumTokenService implements BlockchainServiceInterface
{
    protected $ethereumService;
    protected $cryptocurrency;
    protected $contractAddress;
    protected $decimals;

    public function __construct(Cryptocurrency $cryptocurrency)
    {
        $this->cryptocurrency = $cryptocurrency;
        $this->contractAddress = $cryptocurrency->contract_address;
        $this->decimals = $cryptocurrency->decimals;
        $this->ethereumService = new EthereumService();

        if (!$this->contractAddress) {
            throw new \RuntimeException("Token contract address not configured for {$cryptocurrency->symbol}");
        }
    }

    /**
     * Get token balance (delegates to Ethereum service)
     */
    public function getBalance(string $address): float
    {
        return $this->ethereumService->getTokenBalance(
            $address, 
            $this->contractAddress, 
            $this->decimals
        );
    }

    /**
     * Get transaction confirmations (delegates to Ethereum service)
     */
    public function getConfirmations(string $txHash): int
    {
        return $this->ethereumService->getConfirmations($txHash);
    }

    /**
     * Find token transaction by address and amount
     */
    public function findTransactionByAddress(string $address, float $amount, int $hours = 24): ?string
    {
        return $this->ethereumService->findTokenTransactionByAddress(
            $address,
            $this->contractAddress,
            $amount,
            $this->decimals,
            $hours
        );
    }

    /**
     * Get transaction details (delegates to Ethereum service)
     */
    public function getTransaction(string $txHash): ?array
    {
        $transaction = $this->ethereumService->getTransaction($txHash);
        
        if ($transaction) {
            // Add token-specific information
            $receipt = $this->ethereumService->getTransactionReceipt($txHash);
            
            if ($receipt && isset($receipt['logs'])) {
                $transaction['token_transfers'] = $this->parseTokenTransfers($receipt['logs']);
            }
        }
        
        return $transaction;
    }

    /**
     * Parse token transfer events from transaction logs
     */
    protected function parseTokenTransfers(array $logs): array
    {
        $transfers = [];
        
        // ERC-20 Transfer event signature
        $transferSignature = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        
        foreach ($logs as $log) {
            if (isset($log['topics'][0]) && 
                $log['topics'][0] === $transferSignature &&
                strtolower($log['address']) === strtolower($this->contractAddress)) {
                
                $from = '0x' . substr($log['topics'][1], 26);
                $to = '0x' . substr($log['topics'][2], 26);
                $value = hexdec($log['data']);
                
                $transfers[] = [
                    'from' => $from,
                    'to' => $to,
                    'value' => $value / pow(10, $this->decimals),
                    'contract' => $log['address'],
                    'symbol' => $this->cryptocurrency->symbol
                ];
            }
        }
        
        return $transfers;
    }

    /**
     * Validate Ethereum address (delegates to Ethereum service)
     */
    public function isValidAddress(string $address): bool
    {
        return $this->ethereumService->isValidAddress($address);
    }

    /**
     * Not implemented for tokens
     */
    public function generateAddress(): ?array
    {
        throw new \RuntimeException("Token address generation not supported");
    }

    /**
     * Not implemented for tokens
     */
    public function sendTransaction(string $fromAddress, string $toAddress, float $amount, string $privateKey): ?string
    {
        throw new \RuntimeException("Token transaction sending not implemented in this example");
    }

    /**
     * Get token information
     */
    public function getTokenInfo(): array
    {
        try {
            // This would typically make contract calls to get token metadata
            // For simplicity, returning stored information
            return [
                'name' => $this->cryptocurrency->name,
                'symbol' => $this->cryptocurrency->symbol,
                'decimals' => $this->decimals,
                'contract_address' => $this->contractAddress,
                'total_supply' => null, // Would need contract call
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get token info", [
                'symbol' => $this->cryptocurrency->symbol,
                'contract' => $this->contractAddress,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get token holders (top holders)
     */
    public function getTopHolders(int $limit = 100): array
    {
        try {
            // This would require additional API endpoints or indexing
            // Most block explorers provide this information
            Log::info("Token holders lookup not implemented", [
                'symbol' => $this->cryptocurrency->symbol,
                'contract' => $this->contractAddress
            ]);
            
            return [];
        } catch (\Exception $e) {
            Log::error("Failed to get token holders", [
                'symbol' => $this->cryptocurrency->symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Check if token transfer is valid
     */
    public function validateTokenTransfer(string $txHash, string $toAddress, float $amount): bool
    {
        try {
            $transaction = $this->getTransaction($txHash);
            
            if (!$transaction || !isset($transaction['token_transfers'])) {
                return false;
            }
            
            foreach ($transaction['token_transfers'] as $transfer) {
                if (strtolower($transfer['to']) === strtolower($toAddress) &&
                    abs($transfer['value'] - $amount) < 0.000001) { // Small tolerance for floating point
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("Failed to validate token transfer", [
                'txHash' => $txHash,
                'toAddress' => $toAddress,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}