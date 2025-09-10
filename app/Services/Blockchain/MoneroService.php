<?php

namespace App\Services\Blockchain;

class MoneroService extends BlockbookBaseService
{
    public function __construct()
    {
        parent::__construct('xmr');
    }

    /**
     * Monero-specific address validation
     */
    public function isValidAddress(string $address): bool
    {
        // Monero address validation patterns
        $patterns = [
            '/^4[0-9AB][1-9A-HJ-NP-Za-km-z]{93}$/',     // Standard address
            '/^8[0-9AB][1-9A-HJ-NP-Za-km-z]{93}$/',     // Integrated address
            '/^5[0-9AB][1-9A-HJ-NP-Za-km-z]{93}$/',     // Subaddress
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $address)) {
                // Additional validation using Blockbook API
                return parent::isValidAddress($address);
            }
        }
        
        return false;
    }

    /**
     * Monero uses 12 decimal places
     */
    protected function fromBaseUnits(string $amount): float
    {
        return (float)$amount / 1000000000000; // 10^12
    }

    /**
     * Convert to atomic units (piconero)
     */
    protected function toBaseUnits(float $amount): string
    {
        return (string)($amount * 1000000000000);
    }

    /**
     * Get Monero network info
     */
    public function getNetworkInfo(): array
    {
        try {
            $data = $this->makeRequest('/api/v2');
            
            if ($data && isset($data['blockbook'])) {
                return [
                    'chain' => $data['blockbook']['coin'] ?? 'Monero',
                    'blocks' => $data['blockbook']['bestHeight'] ?? 0,
                    'difficulty' => $data['backend']['difficulty'] ?? 0,
                    'version' => $data['blockbook']['version'] ?? null,
                    'hashrate' => $data['backend']['hashrate'] ?? null
                ];
            }
            
            return [];
            
        } catch (\Exception $e) {
            Log::error("Failed to get XMR network info", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get transaction details (limited due to Monero privacy)
     */
    public function getTransaction(string $txHash): ?array
    {
        try {
            $data = $this->makeRequest("/api/v2/tx/{$txHash}");
            
            if ($data) {
                return [
                    'txid' => $data['txid'] ?? null,
                    'confirmations' => $data['confirmations'] ?? 0,
                    'blockHeight' => $data['blockHeight'] ?? null,
                    'blockTime' => $data['blockTime'] ?? null,
                    'size' => $data['size'] ?? 0,
                    // Note: Monero amounts are private, so limited info available
                    'fee' => isset($data['fees']) ? $this->fromBaseUnits($data['fees']) : null,
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to get XMR transaction", [
                'txHash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Note: Due to Monero's privacy features, finding specific transactions
     * by amount is not possible through public explorers
     */
    public function findTransactionByAddress(string $address, float $amount, int $hours = 24): ?string
    {
        Log::warning("XMR transaction lookup by amount is not supported due to privacy features", [
            'address' => $address,
            'amount' => $amount
        ]);
        
        // For Monero, you would typically need to use view keys or 
        // implement integration with monero-wallet-rpc
        return null;
    }

    /**
     * Get block information
     */
    public function getBlock(string $blockHashOrHeight): ?array
    {
        try {
            $data = $this->makeRequest("/api/v2/block/{$blockHashOrHeight}");
            
            if ($data) {
                return [
                    'hash' => $data['hash'] ?? null,
                    'height' => $data['height'] ?? 0,
                    'time' => $data['time'] ?? null,
                    'txCount' => $data['txCount'] ?? 0,
                    'size' => $data['size'] ?? 0,
                    'difficulty' => $data['difficulty'] ?? null,
                    'previousBlockHash' => $data['previousBlockHash'] ?? null,
                    'reward' => isset($data['reward']) ? $this->fromBaseUnits($data['reward']) : null
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to get XMR block", [
                'block' => $blockHashOrHeight,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if address has received any payments (view-only)
     */
    public function hasReceivedPayments(string $address): bool
    {
        try {
            $data = $this->makeRequest("/api/v2/address/{$address}");
            
            if ($data && isset($data['txs'])) {
                return (int)$data['txs'] > 0;
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("Failed to check XMR payments", [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}