<?php

namespace App\Services\Blockchain;

use App\Services\Blockchain\Contracts\BlockchainServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

abstract class BlockbookBaseService implements BlockchainServiceInterface
{
    protected $baseUrl;
    protected $currency;
    protected $decimals;
    protected $timeout;
    protected $retryAttempts;

    public function __construct(string $currency)
    {
        $this->currency = strtolower($currency);
        $this->decimals = config("crypto.networks.{$this->currency}.decimals", 8);
        $this->timeout = config('crypto.blockbook.timeout', 30);
        $this->retryAttempts = config('crypto.blockbook.retry_attempts', 3);
        
        $isTestnet = config("crypto.networks.{$this->currency}.testnet", false);
        $configKey = $isTestnet ? 'testnet_blockbook_url' : 'blockbook_url';
        $this->baseUrl = config("crypto.networks.{$this->currency}.{$configKey}");
        
        if (!$this->baseUrl) {
            throw new \RuntimeException("No Blockbook URL configured for {$currency}");
        }
    }

    /**
     * Make HTTP request to Blockbook API with retry logic
     */
    protected function makeRequest(string $endpoint, array $params = []): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        
        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)->get($url, $params);
                
                if ($response->successful()) {
                    return $response->json();
                }
                
                if ($response->status() === 429) { // Rate limited
                    $delay = config('crypto.blockbook.rate_limit_delay', 1);
                    sleep($delay * $attempt);
                    continue;
                }
                
                Log::warning("Blockbook API request failed", [
                    'url' => $url,
                    'status' => $response->status(),
                    'attempt' => $attempt
                ]);
                
            } catch (\Exception $e) {
                Log::error("Blockbook API request error", [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt === $this->retryAttempts) {
                    throw $e;
                }
                
                sleep($attempt); // Exponential backoff
            }
        }
        
        return null;
    }

    /**
     * Convert from base units to readable amount
     */
    protected function fromBaseUnits(string $amount): float
    {
        return (float)$amount / pow(10, $this->decimals);
    }

    /**
     * Convert from readable amount to base units
     */
    protected function toBaseUnits(float $amount): string
    {
        return (string)($amount * pow(10, $this->decimals));
    }

    /**
     * Get address balance
     */
    public function getBalance(string $address): float
    {
        try {
            $cacheKey = "balance_{$this->currency}_{$address}";
            
            return Cache::remember($cacheKey, 60, function () use ($address) {
                $data = $this->makeRequest("/api/v2/address/{$address}");
                
                if ($data && isset($data['balance'])) {
                    return $this->fromBaseUnits($data['balance']);
                }
                
                return 0;
            });
            
        } catch (\Exception $e) {
            Log::error("Failed to get balance", [
                'currency' => $this->currency,
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get transaction confirmations
     */
    public function getConfirmations(string $txHash): int
    {
        try {
            $data = $this->makeRequest("/api/v2/tx/{$txHash}");
            
            if ($data && isset($data['confirmations'])) {
                return (int)$data['confirmations'];
            }
            
            return 0;
            
        } catch (\Exception $e) {
            Log::error("Failed to get confirmations", [
                'currency' => $this->currency,
                'txHash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Find transaction by address and amount
     */
    public function findTransactionByAddress(string $address, float $amount, int $hours = 24): ?string
    {
        try {
            $targetAmount = $this->toBaseUnits($amount);
            $cutoffTime = time() - ($hours * 3600);
            
            // Get address transactions
            $data = $this->makeRequest("/api/v2/address/{$address}", [
                'page' => 1,
                'pageSize' => 50
            ]);
            
            if (!$data || !isset($data['transactions'])) {
                return null;
            }
            
            foreach ($data['transactions'] as $tx) {
                // Check if transaction is recent
                if (isset($tx['blockTime']) && $tx['blockTime'] < $cutoffTime) {
                    continue;
                }
                
                // Check outputs for matching address and amount
                if (isset($tx['vout'])) {
                    foreach ($tx['vout'] as $output) {
                        if (isset($output['addresses']) && 
                            in_array($address, $output['addresses']) &&
                            isset($output['value']) &&
                            $output['value'] === $targetAmount) {
                            return $tx['txid'];
                        }
                    }
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to find transaction", [
                'currency' => $this->currency,
                'address' => $address,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get transaction details
     */
    public function getTransaction(string $txHash): ?array
    {
        try {
            return $this->makeRequest("/api/v2/tx/{$txHash}");
        } catch (\Exception $e) {
            Log::error("Failed to get transaction", [
                'currency' => $this->currency,
                'txHash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get current block height
     */
    public function getCurrentBlockHeight(): int
    {
        try {
            $data = $this->makeRequest('/api/v2');
            return isset($data['blockbook']['bestHeight']) ? (int)$data['blockbook']['bestHeight'] : 0;
        } catch (\Exception $e) {
            Log::error("Failed to get block height", [
                'currency' => $this->currency,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Validate address format using Blockbook
     */
    public function isValidAddress(string $address): bool
    {
        try {
            $data = $this->makeRequest("/api/v2/address/{$address}");
            return $data !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Default implementation - not supported for most currencies
     */
    public function generateAddress(): ?array
    {
        throw new \RuntimeException("Address generation not supported for {$this->currency}");
    }

    /**
     * Default implementation - not supported for most currencies  
     */
    public function sendTransaction(string $fromAddress, string $toAddress, float $amount, string $privateKey): ?string
    {
        throw new \RuntimeException("Transaction sending not supported for {$this->currency}");
    }
}