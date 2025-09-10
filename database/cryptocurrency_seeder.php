<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Cryptocurrency;
use App\Models\WalletAddress;

class CryptocurrencySeeder extends Seeder
{
    public function run(): void
    {
        // Bitcoin
        $btc = Cryptocurrency::create([
            'symbol' => 'BTC',
            'name' => 'Bitcoin',
            'network' => 'mainnet',
            'min_amount' => 0.00001,
            'max_amount' => 100,
            'fee_percentage' => 0.5,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => false,
            'decimals' => 8,
        ]);

        // Litecoin
        $ltc = Cryptocurrency::create([
            'symbol' => 'LTC',
            'name' => 'Litecoin',
            'network' => 'mainnet',
            'min_amount' => 0.001,
            'max_amount' => 1000,
            'fee_percentage' => 0.25,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => false,
            'decimals' => 8,
        ]);

        // Ethereum
        $eth = Cryptocurrency::create([
            'symbol' => 'ETH',
            'name' => 'Ethereum',
            'network' => 'mainnet',
            'min_amount' => 0.001,
            'max_amount' => 100,
            'fee_percentage' => 0.3,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => false,
            'decimals' => 18,
        ]);

        // Monero
        $xmr = Cryptocurrency::create([
            'symbol' => 'XMR',
            'name' => 'Monero',
            'network' => 'mainnet',
            'min_amount' => 0.01,
            'max_amount' => 1000,
            'fee_percentage' => 0.5,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => false,
            'decimals' => 12,
        ]);

        // Solana
        $sol = Cryptocurrency::create([
            'symbol' => 'SOL',
            'name' => 'Solana',
            'network' => 'mainnet',
            'min_amount' => 0.01,
            'max_amount' => 10000,
            'fee_percentage' => 0.25,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => false,
            'decimals' => 9,
        ]);

        // ERC-20 Tokens
        
        // USDT (Tether)
        $usdt = Cryptocurrency::create([
            'symbol' => 'USDT',
            'name' => 'Tether USD',
            'network' => 'ethereum',
            'min_amount' => 1,
            'max_amount' => 100000,
            'fee_percentage' => 0.1,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => true,
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'decimals' => 6,
        ]);

        // USDC (USD Coin)
        $usdc = Cryptocurrency::create([
            'symbol' => 'USDC',
            'name' => 'USD Coin',
            'network' => 'ethereum',
            'min_amount' => 1,
            'max_amount' => 100000,
            'fee_percentage' => 0.1,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => true,
            'contract_address' => '0xa0b86a33e6cc3b38c941fb2d6ad2c9c0b301ff53',
            'decimals' => 6,
        ]);

        // LINK (Chainlink)
        $link = Cryptocurrency::create([
            'symbol' => 'LINK',
            'name' => 'Chainlink',
            'network' => 'ethereum',
            'min_amount' => 0.1,
            'max_amount' => 10000,
            'fee_percentage' => 0.2,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => true,
            'contract_address' => '0x514910771af9ca656af840dff83e8264ecf986ca',
            'decimals' => 18,
        ]);

        // UNI (Uniswap)
        $uni = Cryptocurrency::create([
            'symbol' => 'UNI',
            'name' => 'Uniswap',
            'network' => 'ethereum',
            'min_amount' => 0.1,
            'max_amount' => 10000,
            'fee_percentage' => 0.25,
            'fixed_fee' => 0,
            'is_active' => true,
            'is_token' => true,
            'contract_address' => '0x1f9840a85d5af5bf1d1762f925bdaddc4201f984',
            'decimals' => 18,
        ]);

        // Sample wallet addresses (replace with your actual addresses)
        $this->createSampleWalletAddresses($btc, [
            'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
            'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq',
            '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'
        ]);

        $this->createSampleWalletAddresses($ltc, [
            'LTC1QXY2KGDYGJRSQTZQ2N0YRF2493P83KKFJHX0WLH',
            'LTDR5V1LG5M52Y9KTDWPEF5FQJCDXMGF9BRKX8XJMU',
            'LdP8Qox1VAhCzLJNqrr74YovaWYyNBUWvL'
        ]);

        $this->createSampleWalletAddresses($eth, [
            '0x742d35Cc6634C0532925a3b8D400D4C5bE7a5d58',
            '0x8ba1f109551bD432803012645Hac136c22C0D7D1',
            '0xE3D30a3f8a0b5b93c6E9aB3c6f8F8c89D0e0f0f0'
        ]);

        $this->createSampleWalletAddresses($sol, [
            '9WzDXwBbmkg8ZTbNMqUxvQRAyrZzDsGYdLVL9zYtAWWM',
            '2WDq7wSs9zYrpx2kbHDA4RUTRch2CCTP6ZWaH4GNHnR',
            'Dn4noZ5jgGfkntzcQSUZ8czkreiZ1ForXYiRiqLybTt'
        ]);

        $this->createSampleWalletAddresses($xmr, [
            '4AdUndXHHZ6cfufTMvppY6JwXNouMBzSkbLYfpAV5Usx3skxNgYeYTRJ5zQ5UjjCYdP5LqdKQNmYM2S3X9P6A1E78nM9t',
            '47vvLrKtZhLHZoaP4RKhQjn5mopq8VcqVKVTKRKq7h9wKKKx8wY29wKf7fNr8q8KLQ8qVKrKq8VcqVKVTKRKq7h9w',
        ]);

        // For tokens, they use the same Ethereum addresses
        $this->createSampleWalletAddresses($usdt, [
            '0x742d35Cc6634C0532925a3b8D400D4C5bE7a5d58',
            '0x8ba1f109551bD432803012645Hac136c22C0D7D1',
        ]);

        $this->createSampleWalletAddresses($usdc, [
            '0x742d35Cc6634C0532925a3b8D400D4C5bE7a5d58',
            '0x8ba1f109551bD432803012645Hac136c22C0D7D1',
        ]);

        $this->createSampleWalletAddresses($link, [
            '0x742d35Cc6634C0532925a3b8D400D4C5bE7a5d58',
        ]);

        $this->createSampleWalletAddresses($uni, [
            '0x742d35Cc6634C0532925a3b8D400D4C5bE7a5d58',
        ]);

        $this->command->info('Cryptocurrencies and wallet addresses seeded successfully!');
    }

    private function createSampleWalletAddresses(Cryptocurrency $crypto, array $addresses): void
    {
        foreach ($addresses as $address) {
            WalletAddress::create([
                'cryptocurrency_id' => $crypto->id,
                'address' => $address,
                'is_active' => true,
            ]);
        }
    }
}