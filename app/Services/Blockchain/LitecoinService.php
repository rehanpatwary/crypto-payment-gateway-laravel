<?php

namespace App\Services\Blockchain;

class LitecoinService extends BlockbookBaseService
{
    public function __construct()
    {
        parent::__construct('ltc');
    }

    /**
     * Litecoin-specific address validation
     */
    public function isValidAddress(string $address): bool
    {
        // Litecoin address validation patterns
        $patterns = [
            '/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/',  // Legacy P2PKH (L) and P2SH (M, 3)
            '/^ltc1[a-z0-9]{39,59}$/',               // Bech32 (Native SegWit)
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
            Log::error("Failed to get LTC UTXOs", [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Estimate transaction fee for Litecoin
     */
    public function estimateFee(int $blocks = 6): float
    {
        try {
            $data = $this->makeRequest('/api/v2/estimatefee', ['blocks' => $blocks]);
            
            if ($data && isset($data['result'])) {
                return (float)$data['result'];
            }
            
            // Fallback fee rate for Litecoin (lower than Bitcoin)
            return 0.000001; // 0.1 litoshi/byte
            
        } catch (\Exception $e) {
            Log::error("Failed to estimate LTC fee", ['error' => $e->getMessage()]);
            return 0.000001;
        }
    }

    /**
     * Get Litecoin network info
     */
    public function getNetworkInfo(): array
    {
        try {
            $data = $this->makeRequest('/api/v2');
            
            if ($data && isset($data['blockbook'])) {
                return [
                    'chain' => $data['blockbook']['coin'] ?? 'Litecoin',
                    'blocks' => $data['blockbook']['bestHeight'] ?? 0,
                    'difficulty' => $data['backend']['difficulty'] ?? 0,
                    'version' => $data['blockbook']['version'] ?? null,
                    'protocolVersion' => $data['backend']['version'] ?? null
                ];
            }
            
            return [];
            
        } catch (\Exception $e) {
            Log::error("Failed to get LTC network info", ['error' => $e->getMessage()]);
            return [];
        }
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
                    'previousBlockHash' => $data['previousBlockHash'] ?? null,
                    'nextBlockHash' => $data['nextBlockHash'] ?? null,
                    'confirmations' => $data['confirmations'] ?? 0
                ];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error("Failed to get LTC block", [
                'block' => $blockHashOrHeight,
                'error' => $e->getMessage()
            ]);
            return null;
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
            Log::error("Failed to broadcast LTC transaction", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}