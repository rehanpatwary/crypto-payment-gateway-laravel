<?php

namespace App\Services\Blockchain;

use App\Services\Blockchain\Contracts\BlockchainServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SolanaService implements BlockchainServiceInterface
{
    protected $rpcUrl;
    protected $timeout;
    protected $decimals = 9; // SOL has 9 decimal places

    public function __construct()
    {
        $isTestnet = config('crypto.networks.sol.testnet', false);
        $this->rpcUrl = $isTestnet 
            ? config('crypto.networks.sol.testnet_rpc_url')
            : config('crypto.networks.sol.rpc_url');
        
        $this->timeout = config('crypto.blockbook.timeout', 30);
        
        if (!$this->rpcUrl) {
            throw new \RuntimeException("No Solana RPC URL configured");
        }
    }

    /**
     * Make RPC request to Solana
     */
    protected function makeRpcRequest(string $method, array $params = []): ?array
    {
        try {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => $method,
                'params' => $params
            ];

            $response = Http::timeout($this->timeout)
                ->post($this->rpcUrl, $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['error'])) {
                    Log::error("Solana RPC error", [
                        'method' => $method,
                        'error' => $data['error']
                    ]);
                    return null;
                }
                
                return $data['result'] ?? null;
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Solana RPC request failed", [
                'method' => $method,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Convert lamports to SOL
     */
    protected function fromLamports(int $lamports): float
    {
        return $lamports / 1000000000; // 10^9
    }

    /**
     * Convert SOL to lamports
     */
    protected function toLamports(float $sol): int
    {
        return (int)($sol * 1000000000);
    }

    /**
     * Get SOL balance
     */
    public function getBalance(string $address): float
    {
        try {
            $cacheKey = "sol_balance_{$address}";
            
            return Cache::remember($cacheKey, 60, function () use ($address) {
                $result = $this->makeRpcRequest('getBalance', [$address]);
                
                if ($result && isset($result['value'])) {
                    return $this->fromLamports($result['value']);
                }
                
                return 0;
            });

        } catch (\Exception $e) {
            Log::error("Failed to get SOL balance", [
                'address' => $address,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get SPL token balance
     */
    public function getTokenBalance(string $address, string $mintAddress): float
    {
        try {
            $result = $this->makeRpcRequest('getTokenAccountsByOwner', [
                $address,
                ['mint' => $mintAddress],
                ['encoding' => 'jsonParsed']
            ]);

            if ($result && isset($result['value']) && !empty($result['value'])) {
                $tokenAccount = $result['value'][0];
                $balance = $tokenAccount['account']['data']['parsed']['info']['tokenAmount'];
                
                return (float)$balance['uiAmount'];
            }

            return 0;

        } catch (\Exception $e) {
            Log::error("Failed to get SPL token balance", [
                'address' => $address,
                'mint' => $mintAddress,
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
            $result = $this->makeRpcRequest('getTransaction', [
                $txHash,
                ['encoding' => 'json', 'maxSupportedTransactionVersion' => 0]
            ]);

            if ($result && isset($result['slot'])) {
                // Get current slot
                $currentSlot = $this->makeRpcRequest('getSlot');
                
                if ($currentSlot) {
                    return max(0, $currentSlot - $result['slot']);
                }
            }

            return 0;

        } catch (\Exception $e) {
            Log::error("Failed to get SOL confirmations", [
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
            $targetAmount = $this->toLamports($amount);
            
            // Get recent transactions for the address
            $result = $this->makeRpcRequest('getSignaturesForAddress', [
                $address,
                ['limit' => 50]
            ]);

            if (!$result) {
                return null;
            }

            foreach ($result as $signature) {
                // Check if transaction is recent
                if (isset($signature['blockTime'])) {
                    $txTime = $signature['blockTime'];
                    if ($txTime < (time() - ($hours * 3600))) {
                        continue;
                    }
                }

                // Get transaction details
                $txDetails = $this->makeRpcRequest('getTransaction', [
                    $signature['signature'],
                    ['encoding' => 'json', 'maxSupportedTransactionVersion' => 0]
                ]);

                if ($txDetails && isset($txDetails['transaction']['message'])) {
                    $message = $txDetails['transaction']['message'];
                    
                    // Check if this transaction sent the target amount to our address
                    if ($this->isTransactionMatch($message, $address, $targetAmount)) {
                        return $signature['signature'];
                    }
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Failed to find SOL transaction", [
                'address' => $address,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if transaction matches our criteria
     */
    protected function isTransactionMatch(array $message, string $targetAddress, int $targetAmount): bool
    {
        if (!isset($message['instructions'])) {
            return false;
        }

        foreach ($message['instructions'] as $instruction) {
            // Check for system program transfer instruction
            if (isset($instruction['parsed']['type']) && 
                $instruction['parsed']['type'] === 'transfer') {
                
                $info = $instruction['parsed']['info'];
                
                if ($info['destination'] === $targetAddress && 
                    $info['lamports'] === $targetAmount) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get transaction details
     */
    public function getTransaction(string $txHash): ?array
    {
        try {
            $result = $this->makeRpcRequest('getTransaction', [
                $txHash,
                ['encoding' => 'json', 'maxSupportedTransactionVersion' => 0]
            ]);

            if ($result) {
                return [
                    'signature' => $txHash,
                    'slot' => $result['slot'] ?? null,
                    'blockTime' => $result['blockTime'] ?? null,
                    'confirmations' => $this->getConfirmations($txHash),
                    'fee' => isset($result['meta']['fee']) ? $this->fromLamports($result['meta']['fee']) : null,
                    'status' => $result['meta']['err'] ? 'failed' : 'success'
                ];
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Failed to get SOL transaction", [
                'txHash' => $txHash,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate Solana address
     */
    public function isValidAddress(string $address): bool
    {
        // Solana addresses are base58 encoded and 32-44 characters long
        if (strlen($address) < 32 || strlen($address) > 44) {
            return false;
        }

        // Check if it contains only valid base58 characters
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]+$/', $address)) {
            return false;
        }

        try {
            // Verify by checking account info
            $result = $this->makeRpcRequest('getAccountInfo', [$address]);
            return $result !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Not implemented for this example
     */
    public function generateAddress(): ?array
    {
        throw new \RuntimeException("Solana address generation not implemented in this example");
    }

    /**
     * Not implemented for this example
     */
    public function sendTransaction(string $fromAddress, string $toAddress, float $amount, string $privateKey): ?string
    {
        throw new \RuntimeException("Solana transaction sending not implemented in this example");
    }

    /**
     * Get current slot (block height equivalent)
     */
    public function getCurrentSlot(): int
    {
        try {
            $result = $this->makeRpcRequest('getSlot');
            return $result ? (int)$result : 0;
        } catch (\Exception $e) {
            Log::error("Failed to get current slot", ['error' => $e->getMessage()]);
            return 0;
        }
    }
}