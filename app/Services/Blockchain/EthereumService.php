<?php

namespace App\Services\Blockchain;

class EthereumService extends BlockbookBaseService
{
    public function __construct()
    {
        parent::__construct('eth');
    }

    /**
     * Ethereum-specific address validation
     */
    public function isValidAddress(string $address): bool
    {
        // Ethereum address validation pattern
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return false;
        }

        // Additional validation using Blockbook API
        return parent::isValidAddress($address);
    }

    /**
     * Get ERC-20 token balance
     */
    public function getTokenBalance(string $address, string $contractAddress, int $decimals = 18): float
    {
        try {
            $cacheKey = "token_balance_{$contractAddress}_{$address}";
            
            return Cache::remember($cacheKey, 60, function () use ($address, $contractAddress, $decimals) {
                $data = $this->makeRequest("/api/v2/address/{$address}", [
                    'details' => 'tokenBalances'
                ]);
                
                if ($data && isset($data['tokens'])) {
                    foreach ($data['tokens'] as $token) {
                        if (strtolower($token['contract']) === strtolower($contractAddress)) {
                            return (float)$token['balance'] / pow(10, $decimals);
                        }
                    }
                }
                
                return 0;
            });
            
        } catch (\Exception $e) {
            Log::error("Failed to get token balance", [
                'address' => $address,
                'contract' => $contractAddress,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get token transfer history
     */
    public function getTokenTransfers(string $address, string $contractAddress = null, int $page = 1): array
    {
        try {
            $params = [
                'page' => $page,
                'pageSize' => 25,
                'details' => 'tokenTransfers'
            ];
            
            if ($contractAddress) {
                $params['contract'] = $contractAddress;
            }
            
            $data = $this->makeRequest("/api/v2/address/{$address}", $params);
            
            if ($data && isset($data['tokenTransfers'])) {
                return array_map(function ($transfer) {
                    return [
                        'txid' => $transfer['txid'],
                        'from' => $transfer['from'] ?? null,
                        'to' => $transfer['to'] ?? null,
                        'contract' => $transfer['contract'] ?? null,
                        'value' => $transfer['value'] ?? '0',
                        'symbol' => $transfer['symbol'] ?? null,
                        'decimals' => $transfer['decimals'] ?? 18,
                        'blockTime' => $transfer['blockTime'] ?? null,
                        'confirmations' => $transfer['confirmations'] ?? 0
                    ];
                }, $data['tokenTransfers']);
            }
            
            return [];
            
        } catch (\Exception $e) {
            Log::error("Failed to get token transfers", [
                'address' => $address,
                'contract' => $contractAddress,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Find token transaction by address and amount
     */
    public function findTokenTransactionByAddress(string $address, string $contractAddress, float $amount, int $decimals = 18, int $hours = 24): ?string
    {
        try {
            $targetAmount = (string)($amount * pow(10, $decimals));
            $cutoffTime = time() - ($hours * 3600);
            
            $transfers = $this->getTokenTransfers($address, $contractAddress);
            
            foreach ($transfers as $transfer) {
                // Check if transaction is recent
                if (isset($transfer['blockTime']) && $transfer['blockTime'] < $cutoffTime) {
                    continue;
                }
                
                // Check if it's incoming to our address and matches amount
                if (strtolower($transfer['to']) === strtolower($address) && 
                    $transfer['value'] === $targetAmount) {
                    return $transfer['txid'];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to find token transaction", [
                'address' => $address,
                'contract' => $contractAddress,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get gas price estimate
     */
    public function estimateGasPrice(): array
    {
        try {
            $data = $this->makeRequest('/api/v2/estimatefee');
            
            if ($data && isset($data['result'])) {
                // Blockbook returns gas price in Gwei
                $gasPrice = (float)$data['result'];
                
                return [
                    'slow' => $gasPrice * 0.8,
                    'standard' => $gasPrice,
                    'fast' => $gasPrice * 1.2,
                    'instant' => $gasPrice * 1.5
                ];
            }
            
            // Fallback gas prices in Gwei
            return [
                'slow' => 10,
                'standard' => 20,
                'fast' => 30,
                'instant' => 40
            ];
            
        } catch (\Exception $e) {
            Log::error("Failed to estimate gas price", ['error' => $e->getMessage()]);
            return [
                'slow' => 10,
                'standard' => 20,
                'fast' => 30,
                'instant' => 40
            ];
        }
    }

    /**
     * Get transaction receipt
     */
    public function getTransactionReceipt(string $txHash): ?array
    {
        try {
            $data = $this->makeRequest("/api/v2/tx/{$txHash}");
            
            if ($data && isset($data['ethereumSpecific'])) {
                $ethData = $data['ethereumSpecific'];
                
                return [
                    'status' => $ethData['status'] ?? null,
                    'gasUsed' => $ethData['gasUsed'] ?? null,
                    'gasPrice' => $ethData['gasPrice'] ?? null,
                    'nonce' => $ethData['nonce'] ?? null,
                    'logs' => $ethData['parsedLogs'] ?? []
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to get transaction receipt", [
                'txHash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get nonce for address
     */
    public function getNonce(string $address): int
    {
        try {
            $data = $this->makeRequest("/api/v2/address/{$address}");
            
            if ($data && isset($data['nonce'])) {
                return (int)$data['nonce'];
            }
            
            return 0;
            
        } catch (\Exception $e) {
            Log::error("Failed to get nonce", [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Broadcast raw transaction
     */
    public function broadcastTransaction(string $rawTx): ?string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post($this->baseUrl . '/api/v2/sendtx/', ['hex' => $rawTx]);
            
            if ($response->successful()) {
                $data = $response->json();
                return $data['result'] ?? null;
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to broadcast ETH transaction", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}