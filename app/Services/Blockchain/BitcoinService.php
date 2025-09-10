<?php

namespace App\Services\Blockchain;

class BitcoinService extends BlockbookBaseService
{
    public function __construct()
    {
        parent::__construct('btc');
    }

    /**
     * Bitcoin-specific address validation
     */
    public function isValidAddress(string $address): bool
    {
        // Bitcoin address validation patterns
        $patterns = [
            '/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/', // Legacy P2PKH and P2SH
            '/^bc1[a-z0-9]{39,59}$/',              // Bech32 (Native SegWit)
            '/^[2][a-km-zA-HJ-NP-Z1-9]{33}$/'      // P2SH
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
     * Get unspent transaction outputs (UTXOs) for an address
     */
    public function getUtxos(string $address): array
    {
        try {
            $data = $this->makeRequest("/api/v2/utxo/{$address}");
            
            if ($data && is_array($data)) {
                return array_map(function ($utxo) {
                    return [
                        'txid' => $utxo['txid'],
                        'vout' => $utxo['vout'],
                        'value' => $this->fromBaseUnits($utxo['value']),
                        'confirmations' => $utxo['confirmations'] ?? 0
                    ];
                }, $data);
            }
            
            return [];
            
        } catch (\Exception $e) {
            Log::error("Failed to get UTXOs", [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Estimate transaction fee
     */
    public function estimateFee(int $blocks = 6): float
    {
        try {
            $data = $this->makeRequest('/api/v2/estimatefee', ['blocks' => $blocks]);
            
            if ($data && isset($data['result'])) {
                return (float)$data['result'];
            }
            
            // Fallback fee rate (satoshis per byte)
            return 0.00001; // 1 sat/byte
            
        } catch (\Exception $e) {
            Log::error("Failed to estimate fee", ['error' => $e->getMessage()]);
            return 0.00001;
        }
    }

    /**
     * Get address transaction history with pagination
     */
    public function getAddressHistory(string $address, int $page = 1, int $pageSize = 25): array
    {
        try {
            $data = $this->makeRequest("/api/v2/address/{$address}", [
                'page' => $page,
                'pageSize' => $pageSize,
                'details' => 'txs'
            ]);
            
            if ($data && isset($data['transactions'])) {
                return [
                    'transactions' => array_map(function ($tx) {
                        return [
                            'txid' => $tx['txid'],
                            'confirmations' => $tx['confirmations'] ?? 0,
                            'value' => isset($tx['value']) ? $this->fromBaseUnits($tx['value']) : 0,
                            'fees' => isset($tx['fees']) ? $this->fromBaseUnits($tx['fees']) : 0,
                            'blockTime' => $tx['blockTime'] ?? null,
                            'blockHash' => $tx['blockHash'] ?? null,
                            'blockHeight' => $tx['blockHeight'] ?? null
                        ];
                    }, $data['transactions']),
                    'totalPages' => $data['totalPages'] ?? 1,
                    'itemsOnPage' => $data['itemsOnPage'] ?? 0
                ];
            }
            
            return ['transactions' => [], 'totalPages' => 0, 'itemsOnPage' => 0];
            
        } catch (\Exception $e) {
            Log::error("Failed to get address history", [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return ['transactions' => [], 'totalPages' => 0, 'itemsOnPage' => 0];
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
            Log::error("Failed to broadcast transaction", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}