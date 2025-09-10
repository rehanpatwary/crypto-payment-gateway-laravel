<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Configuration
    |--------------------------------------------------------------------------
    */
    'default_expiry_minutes' => env('CRYPTO_DEFAULT_EXPIRY_MINUTES', 30),
    'callback_timeout' => env('CRYPTO_CALLBACK_TIMEOUT', 30),
    'max_callback_retries' => env('CRYPTO_MAX_CALLBACK_RETRIES', 3),

    /*
    |--------------------------------------------------------------------------
    | API Keys for External Services
    |--------------------------------------------------------------------------
    */
    'api_keys' => [
        'coingecko' => env('COINGECKO_API_KEY'),
        'moralis' => env('MORALIS_API_KEY'),
        'solscan' => env('SOLSCAN_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trezor Blockbook API Configuration
    |--------------------------------------------------------------------------
    */
    'blockbook' => [
        'timeout' => env('BLOCKBOOK_TIMEOUT', 30),
        'retry_attempts' => env('BLOCKBOOK_RETRY_ATTEMPTS', 3),
        'rate_limit_delay' => env('BLOCKBOOK_RATE_LIMIT_DELAY', 1), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Configuration - Trezor Blockbook Endpoints
    |--------------------------------------------------------------------------
    */
    'networks' => [
        'btc' => [
            'name' => 'Bitcoin',
            'testnet' => env('BTC_TESTNET', false),
            'blockbook_url' => env('BTC_BLOCKBOOK_URL', 'https://btc1.trezor.io'),
            'testnet_blockbook_url' => env('BTC_TESTNET_BLOCKBOOK_URL', 'https://tbtc1.trezor.io'),
            'min_confirmations' => env('BTC_MIN_CONFIRMATIONS', 3),
            'decimals' => 8,
        ],
        
        'ltc' => [
            'name' => 'Litecoin',
            'testnet' => env('LTC_TESTNET', false),
            'blockbook_url' => env('LTC_BLOCKBOOK_URL', 'https://ltc1.trezor.io'),
            'testnet_blockbook_url' => env('LTC_TESTNET_BLOCKBOOK_URL', 'https://tltc1.trezor.io'),
            'min_confirmations' => env('LTC_MIN_CONFIRMATIONS', 6),
            'decimals' => 8,
        ],
        
        'eth' => [
            'name' => 'Ethereum',
            'testnet' => env('ETH_TESTNET', false),
            'blockbook_url' => env('ETH_BLOCKBOOK_URL', 'https://eth1.trezor.io'),
            'testnet_blockbook_url' => env('ETH_TESTNET_BLOCKBOOK_URL', 'https://teth1.trezor.io'),
            'min_confirmations' => env('ETH_MIN_CONFIRMATIONS', 12),
            'decimals' => 18,
        ],

        'xmr' => [
            'name' => 'Monero',
            'testnet' => env('XMR_TESTNET', false),
            'blockbook_url' => env('XMR_BLOCKBOOK_URL', 'https://xmr1.trezor.io'),
            'testnet_blockbook_url' => env('XMR_TESTNET_BLOCKBOOK_URL', 'https://txmr1.trezor.io'),
            'min_confirmations' => env('XMR_MIN_CONFIRMATIONS', 10),
            'decimals' => 12,
        ],

        'sol' => [
            'name' => 'Solana',
            'testnet' => env('SOL_TESTNET', false),
            'rpc_url' => env('SOL_RPC_URL', 'https://api.mainnet-beta.solana.com'),
            'testnet_rpc_url' => env('SOL_TESTNET_RPC_URL', 'https://api.testnet.solana.com'),
            'min_confirmations' => env('SOL_MIN_CONFIRMATIONS', 32),
            'decimals' => 9,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Token Contracts
    |--------------------------------------------------------------------------
    */
    'tokens' => [
        'USDT' => [
            'name' => 'Tether USD',
            'contract_address' => '0xdac17f958d2ee523a2206206994597c13d831ec7',
            'decimals' => 6,
            'network' => 'eth',
        ],
        'USDC' => [
            'name' => 'USD Coin',
            'contract_address' => '0xa0b86a33e6cc3b38c941fb2d6ad2c9c0b301ff53',
            'decimals' => 6,
            'network' => 'eth',
        ],
        'LINK' => [
            'name' => 'Chainlink',
            'contract_address' => '0x514910771af9ca656af840dff83e8264ecf986ca',
            'decimals' => 18,
            'network' => 'eth',
        ],
    ],