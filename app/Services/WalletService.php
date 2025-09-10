<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserWallet;
use App\Models\UserAddress;
use App\Models\Cryptocurrency;
use App\Models\AddressMonitoringJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wallet Service for BIP39/BIP32/BIP44 HD Wallet Management
 * 
 * This service handles:
 * - Mnemonic generation (BIP39)
 * - Hierarchical Deterministic wallet creation (BIP32)
 * - Multi-currency address derivation (BIP44)
 */
class WalletService
{
    // BIP44 coin types
    const COIN_TYPES = [
        'BTC' => 0,
        'LTC' => 2,
        'ETH' => 60,
        'XMR' => 128,
        'SOL' => 501,
        // Tokens use ETH path
        'USDT' => 60,
        'USDC' => 60,
        'LINK' => 60,
        'UNI' => 60,
    ];

    /**
     * Create a new wallet for user with BIP39 mnemonic
     */
    public function createWalletForUser(User $user): UserWallet
    {
        if ($user->userWallet) {
            throw new \RuntimeException("User already has a wallet");
        }

        DB::beginTransaction();
        
        try {
            // Generate BIP39 mnemonic
            $mnemonic = $this->generateMnemonic();
            
            // Generate master keys
            $masterKeys = $this->generateMasterKeys($mnemonic);
            
            // Create wallet record
            $wallet = UserWallet::create([
                'user_id' => $user->id,
                'encrypted_mnemonic' => $mnemonic,
                'master_public_key' => $masterKeys['master_public_key'],
                'created_at' => now()
            ]);

            // Generate initial addresses for all supported cryptocurrencies
            $this->generateInitialAddresses($wallet);

            DB::commit();
            
            Log::info("Wallet created for user", [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id
            ]);

            return $wallet;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create wallet for user", [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate new address for user and cryptocurrency
     */
    public function generateAddress(User $user, string $cryptocurrencySymbol, string $label = null): UserAddress
    {
        $wallet = $user->userWallet;
        if (!$wallet) {
            $wallet = $this->createWalletForUser($user);
        }

        $cryptocurrency = Cryptocurrency::where('symbol', strtoupper($cryptocurrencySymbol))
            ->where('is_active', true)
            ->firstOrFail();

        DB::beginTransaction();
        
        try {
            // Get next address index for this cryptocurrency
            $nextIndex = $this->getNextAddressIndex($wallet, $cryptocurrency);
            
            // Generate address
            $addressData = $this->generateAddressForIndex($wallet, $cryptocurrency, $nextIndex);
            
            // Create address record
            $userAddress = UserAddress::create([
                'user_id' => $user->id,
                'user_wallet_id' => $wallet->id,
                'cryptocurrency_id' => $cryptocurrency->id,
                'address' => $addressData['address'],
                'derivation_path' => $addressData['derivation_path'],
                'address_index' => $nextIndex,
                'public_key' => $addressData['public_key'],
                'encrypted_private_key' => $addressData['private_key'],
                'balance' => 0,
                'is_active' => true,
                'label' => $label
            ]);

            // Create monitoring job
            AddressMonitoringJob::create([
                'user_address_id' => $userAddress->id,
                'cryptocurrency_id' => $cryptocurrency->id,
                'address' => $addressData['address'],
                'is_active' => true
            ]);

            DB::commit();
            
            Log::info("Address generated", [
                'user_id' => $user->id,
                'cryptocurrency' => $cryptocurrencySymbol,
                'address' => $addressData['address'],
                'derivation_path' => $addressData['derivation_path']
            ]);

            return $userAddress;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to generate address", [
                'user_id' => $user->id,
                'cryptocurrency' => $cryptocurrencySymbol,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate multiple addresses at once
     */
    public function generateMultipleAddresses(User $user, string $cryptocurrencySymbol, int $count, array $labels = []): array
    {
        $addresses = [];
        
        for ($i = 0; $i < $count; $i++) {
            $label = $labels[$i] ?? null;
            $addresses[] = $this->generateAddress($user, $cryptocurrencySymbol, $label);
        }
        
        return $addresses;
    }

    /**
     * Get user addresses for cryptocurrency
     */
    public function getUserAddresses(User $user, string $cryptocurrencySymbol = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = $user->userAddresses()->with('cryptocurrency')->where('is_active', true);
        
        if ($cryptocurrencySymbol) {
            $query->whereHas('cryptocurrency', function ($q) use ($cryptocurrencySymbol) {
                $q->where('symbol', strtoupper($cryptocurrencySymbol));
            });
        }
        
        return $query->orderBy('address_index')->get();
    }

    /**
     * Generate BIP39 24-word mnemonic
     */
    protected function generateMnemonic(): string
    {
        // BIP39 wordlist (simplified - in production use proper BIP39 library)
        $words = $this->getBip39Wordlist();
        
        // Generate 24 random words
        $mnemonic = [];
        for ($i = 0; $i < 24; $i++) {
            $mnemonic[] = $words[array_rand($words)];
        }
        
        return implode(' ', $mnemonic);
    }

    /**
     * Generate master keys from mnemonic
     */
    protected function generateMasterKeys(string $mnemonic): array
    {
        // In production, use a proper BIP32 library like:
        // BitWasp\Bitcoin\Mnemonic\MnemonicFactory
        // BitWasp\Bitcoin\Key\Deterministic\HdPrefix\GlobalPrefixConfig
        
        // For this example, we'll create a simplified version
        $seed = hash('sha512', $mnemonic . 'mnemonic', true);
        $masterPrivateKey = bin2hex(substr($seed, 0, 32));
        $masterPublicKey = $this->derivePublicKey($masterPrivateKey);
        
        return [
            'master_private_key' => $masterPrivateKey,
            'master_public_key' => $masterPublicKey
        ];
    }

    /**
     * Generate address for specific index using BIP44 derivation
     */
    protected function generateAddressForIndex(UserWallet $wallet, Cryptocurrency $cryptocurrency, int $index): array
    {
        $coinType = self::COIN_TYPES[$cryptocurrency->symbol] ?? 0;
        
        // BIP44 derivation path: m/44'/coin_type'/0'/0/address_index
        $derivationPath = "m/44'/{$coinType}'/0'/0/{$index}";
        
        // Derive keys using the path
        $keys = $this->deriveKeysFromPath($wallet, $derivationPath);
        
        // Generate address based on cryptocurrency type
        $address = $this->generateAddressFromKeys($keys, $cryptocurrency);
        
        return [
            'address' => $address,
            'derivation_path' => $derivationPath,
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key']
        ];
    }

    /**
     * Derive keys from BIP44 path
     */
    protected function deriveKeysFromPath(UserWallet $wallet, string $path): array
    {
        // This is a simplified implementation
        // In production, use proper BIP32 key derivation
        
        $mnemonic = $wallet->getDecryptedMnemonic();
        $seed = hash('sha512', $mnemonic . $path, true);
        
        $privateKey = bin2hex(substr($seed, 0, 32));
        $publicKey = $this->derivePublicKey($privateKey);
        
        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey
        ];
    }

    /**
     * Generate address from keys based on cryptocurrency
     */
    protected function generateAddressFromKeys(array $keys, Cryptocurrency $cryptocurrency): string
    {
        switch ($cryptocurrency->symbol) {
            case 'BTC':
                return $this->generateBitcoinAddress($keys['public_key']);
                
            case 'LTC':
                return $this->generateLitecoinAddress($keys['public_key']);
                
            case 'ETH':
            case 'USDT':
            case 'USDC':
            case 'LINK':
            case 'UNI':
                return $this->generateEthereumAddress($keys['public_key']);
                
            case 'XMR':
                return $this->generateMoneroAddress($keys['public_key']);
                
            case 'SOL':
                return $this->generateSolanaAddress($keys['public_key']);
                
            default:
                throw new \RuntimeException("Address generation not supported for {$cryptocurrency->symbol}");
        }
    }

    /**
     * Generate initial addresses for all supported cryptocurrencies
     */
    protected function generateInitialAddresses(UserWallet $wallet): void
    {
        $cryptocurrencies = Cryptocurrency::active()->get();
        
        foreach ($cryptocurrencies as $crypto) {
            try {
                $addressData = $this->generateAddressForIndex($wallet, $crypto, 0);
                
                $userAddress = UserAddress::create([
                    'user_id' => $wallet->user_id,
                    'user_wallet_id' => $wallet->id,
                    'cryptocurrency_id' => $crypto->id,
                    'address' => $addressData['address'],
                    'derivation_path' => $addressData['derivation_path'],
                    'address_index' => 0,
                    'public_key' => $addressData['public_key'],
                    'encrypted_private_key' => $addressData['private_key'],
                    'balance' => 0,
                    'is_active' => true,
                    'label' => 'Default Address'
                ]);

                // Create monitoring job
                AddressMonitoringJob::create([
                    'user_address_id' => $userAddress->id,
                    'cryptocurrency_id' => $crypto->id,
                    'address' => $addressData['address'],
                    'is_active' => true
                ]);
                
            } catch (\Exception $e) {
                Log::error("Failed to generate initial address", [
                    'wallet_id' => $wallet->id,
                    'cryptocurrency' => $crypto->symbol,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get next address index for cryptocurrency
     */
    protected function getNextAddressIndex(UserWallet $wallet, Cryptocurrency $cryptocurrency): int
    {
        $lastAddress = UserAddress::where('user_wallet_id', $wallet->id)
            ->where('cryptocurrency_id', $cryptocurrency->id)
            ->orderBy('address_index', 'desc')
            ->first();
        
        return $lastAddress ? $lastAddress->address_index + 1 : 0;
    }

    // Simplified address generation methods (use proper libraries in production)
    
    protected function derivePublicKey(string $privateKey): string
    {
        // Simplified - use proper secp256k1 in production
        return hash('sha256', $privateKey);
    }

    protected function generateBitcoinAddress(string $publicKey): string
    {
        // Simplified Bitcoin address generation
        // Use proper Bitcoin address generation library in production
        $hash = hash('ripemd160', hash('sha256', hex2bin($publicKey), true), true);
        return 'bc1' . substr(bin2hex($hash), 0, 38); // Simplified bech32
    }

    protected function generateLitecoinAddress(string $publicKey): string
    {
        $hash = hash('ripemd160', hash('sha256', hex2bin($publicKey), true), true);
        return 'ltc1' . substr(bin2hex($hash), 0, 38);
    }

    protected function generateEthereumAddress(string $publicKey): string
    {
        $hash = hash('keccak256', hex2bin($publicKey), true);
        return '0x' . substr(bin2hex($hash), -40);
    }

    protected function generateMoneroAddress(string $publicKey): string
    {
        $hash = hash('keccak256', hex2bin($publicKey), true);
        return '4' . substr(bin2hex($hash), 0, 94);
    }

    protected function generateSolanaAddress(string $publicKey): string
    {
        // Simplified Solana address generation
        return substr(base64_encode(hex2bin($publicKey)), 0, 44);
    }

    /**
     * Get BIP39 wordlist (simplified - first 100 words)
     */
    protected function getBip39Wordlist(): array
    {
        return [
            'abandon', 'ability', 'able', 'about', 'above', 'absent', 'absorb', 'abstract', 'absurd', 'abuse',
            'access', 'accident', 'account', 'accuse', 'achieve', 'acid', 'acoustic', 'acquire', 'across', 'act',
            'action', 'actor', 'actress', 'actual', 'adapt', 'add', 'addict', 'address', 'adjust', 'admit',
            'adult', 'advance', 'advice', 'aerobic', 'affair', 'afford', 'afraid', 'again', 'agent', 'agree',
            'ahead', 'aim', 'air', 'airport', 'aisle', 'alarm', 'album', 'alcohol', 'alert', 'alien',
            'all', 'alley', 'allow', 'almost', 'alone', 'alpha', 'already', 'also', 'alter', 'always',
            'amateur', 'amazing', 'among', 'amount', 'amused', 'analyst', 'anchor', 'ancient', 'anger', 'angle',
            'angry', 'animal', 'ankle', 'announce', 'annual', 'another', 'answer', 'antenna', 'antique', 'anxiety',
            'any', 'apart', 'apology', 'appear', 'apple', 'approve', 'april', 'arch', 'arctic', 'area',
            'arena', 'argue', 'arm', 'armed', 'armor', 'army', 'around', 'arrange', 'arrest', 'arrive'
        ];
    }
}