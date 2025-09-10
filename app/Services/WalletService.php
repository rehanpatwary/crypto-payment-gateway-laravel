<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserWallet;
use App\Models\UserAddress;
use App\Models\Cryptocurrency;
use App\Models\AddressMonitoringJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    // BIP44 coin types
    const COIN_TYPES = [
        'BTC' => 0,
        'LTC' => 2,
        'ETH' => 60,
        'XMR' => 128,
        'SOL' => 501,
    ];

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
            throw $e;
        }
    }

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
            $nextIndex = $this->getNextAddressIndex($wallet, $cryptocurrency);
            $addressData = $this->generateAddressForIndex($wallet, $cryptocurrency, $nextIndex);
            
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

            AddressMonitoringJob::create([
                'user_address_id' => $userAddress->id,
                'cryptocurrency_id' => $cryptocurrency->id,
                'address' => $addressData['address'],
                'is_active' => true
            ]);

            DB::commit();
            return $userAddress;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function generateMnemonic(): string
    {
        $words = $this->getBip39Wordlist();
        $mnemonic = [];
        for ($i = 0; $i < 24; $i++) {
            $mnemonic[] = $words[array_rand($words)];
        }
        return implode(' ', $mnemonic);
    }

    protected function generateMasterKeys(string $mnemonic): array
    {
        $seed = hash('sha512', $mnemonic . 'mnemonic', true);
        $masterPrivateKey = bin2hex(substr($seed, 0, 32));
        $masterPublicKey = $this->derivePublicKey($masterPrivateKey);
        
        return [
            'master_private_key' => $masterPrivateKey,
            'master_public_key' => $masterPublicKey
        ];
    }

    protected function generateAddressForIndex(UserWallet $wallet, Cryptocurrency $cryptocurrency, int $index): array
    {
        $coinType = self::COIN_TYPES[$cryptocurrency->symbol] ?? 0;
        $derivationPath = "m/44'/{$coinType}'/0'/0/{$index}";
        
        $keys = $this->deriveKeysFromPath($wallet, $derivationPath);
        $address = $this->generateAddressFromKeys($keys, $cryptocurrency);
        
        return [
            'address' => $address,
            'derivation_path' => $derivationPath,
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key']
        ];
    }

    protected function deriveKeysFromPath(UserWallet $wallet, string $path): array
    {
        $mnemonic = $wallet->getDecryptedMnemonic();
        $seed = hash('sha512', $mnemonic . $path, true);
        
        $privateKey = bin2hex(substr($seed, 0, 32));
        $publicKey = $this->derivePublicKey($privateKey);
        
        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey
        ];
    }

    protected function generateAddressFromKeys(array $keys, Cryptocurrency $cryptocurrency): string
    {
        switch ($cryptocurrency->symbol) {
            case 'BTC':
                return $this->generateBitcoinAddress($keys['public_key']);
            case 'ETH':
            case 'USDT':
            case 'USDC':
                return $this->generateEthereumAddress($keys['public_key']);
            default:
                return $this->generateGenericAddress($keys['public_key'], $cryptocurrency->symbol);
        }
    }

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

    protected function getNextAddressIndex(UserWallet $wallet, Cryptocurrency $cryptocurrency): int
    {
        $lastAddress = UserAddress::where('user_wallet_id', $wallet->id)
            ->where('cryptocurrency_id', $cryptocurrency->id)
            ->orderBy('address_index', 'desc')
            ->first();
        
        return $lastAddress ? $lastAddress->address_index + 1 : 0;
    }

    // Simplified address generation methods
    protected function derivePublicKey(string $privateKey): string
    {
        return hash('sha256', $privateKey);
    }

    protected function generateBitcoinAddress(string $publicKey): string
    {
        $hash = hash('ripemd160', hash('sha256', hex2bin($publicKey), true), true);
        return 'bc1' . substr(bin2hex($hash), 0, 38);
    }

    protected function generateEthereumAddress(string $publicKey): string
    {
        $hash = hash('sha256', hex2bin($publicKey), true);
        return '0x' . substr(bin2hex($hash), -40);
    }

    protected function generateGenericAddress(string $publicKey, string $symbol): string
    {
        $hash = hash('sha256', hex2bin($publicKey), true);
        $prefix = strtolower(substr($symbol, 0, 3));
        return $prefix . '1' . substr(bin2hex($hash), 0, 38);
    }

    protected function getBip39Wordlist(): array
    {
        return [
            'abandon', 'ability', 'able', 'about', 'above', 'absent', 'absorb', 'abstract', 'absurd', 'abuse',
            'access', 'accident', 'account', 'accuse', 'achieve', 'acid', 'acoustic', 'acquire', 'across', 'act',
            'action', 'actor', 'actress', 'actual', 'adapt', 'add', 'addict', 'address', 'adjust', 'admit',
            'adult', 'advance', 'advice', 'aerobic', 'affair', 'afford', 'afraid', 'again', 'agent', 'agree',
            'ahead', 'aim', 'air', 'airport', 'aisle', 'alarm', 'album', 'alcohol', 'alert', 'alien',
            // ... Add more words as needed
        ];
    }
}
