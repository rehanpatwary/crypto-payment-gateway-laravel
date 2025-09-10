<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Cryptocurrency;

class CryptocurrencySeeder extends Seeder
{
    public function run(): void
    {
        // Bitcoin
        Cryptocurrency::create([
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

        // Ethereum
        Cryptocurrency::create([
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

        // USDT Token
        Cryptocurrency::create([
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

        $this->command->info('Cryptocurrencies seeded successfully!');
    }
}
