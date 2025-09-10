#!/bin/bash

echo "🚀 Setting up Crypto Payment Gateway with HD Wallets..."

# Create directory structure
echo "📁 Creating directory structure..."
mkdir -p app/Console/Commands
mkdir -p app/Http/Controllers/Api
mkdir -p app/Http/Controllers/Auth
mkdir -p app/Http/Requests
mkdir -p app/Services/Blockchain/Contracts
mkdir -p database/migrations
mkdir -p database/seeders
mkdir -p resources/views/demo
mkdir -p config

# Install required packages
echo "📦 Installing required packages..."
composer require laravel/sanctum

# Publish Sanctum migrations
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

echo "📝 Creating all application files..."

# Create migration files
echo "Creating migration files..."

cat > database/migrations/2025_01_01_000001_create_crypto_payment_tables.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Supported cryptocurrencies table
        Schema::create('cryptocurrencies', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10)->unique(); // BTC, ETH, etc.
            $table->string('name');
            $table->string('network')->nullable(); // mainnet, testnet
            $table->decimal('min_amount', 20, 8)->default(0);
            $table->decimal('max_amount', 20, 8)->nullable();
            $table->decimal('fee_percentage', 5, 4)->default(0); // 0.0025 = 0.25%
            $table->decimal('fixed_fee', 20, 8)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_token')->default(false);
            $table->string('contract_address')->nullable(); // For tokens
            $table->integer('decimals')->default(18);
            $table->string('rpc_url')->nullable();
            $table->timestamps();
        });

        // Wallet addresses for receiving payments
        Schema::create('wallet_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cryptocurrency_id')->constrained()->onDelete('cascade');
            $table->string('address')->unique();
            $table->string('private_key')->nullable(); // Encrypted
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Payment transactions
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->foreignId('cryptocurrency_id')->constrained();
            $table->foreignId('wallet_address_id')->constrained();
            $table->decimal('amount', 20, 8);
            $table->decimal('amount_usd', 20, 2)->nullable();
            $table->string('from_address')->nullable();
            $table->string('to_address');
            $table->string('blockchain_tx_hash')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'failed', 'expired'])->default('pending');
            $table->integer('confirmations')->default(0);
            $table->integer('required_confirmations')->default(3);
            $table->timestamp('expires_at');
            $table->json('metadata')->nullable(); // Additional data
            $table->string('callback_url')->nullable();
            $table->boolean('callback_sent')->default(false);
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('blockchain_tx_hash');
        });

        // Exchange rates cache
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 10);
            $table->decimal('rate_usd', 20, 8);
            $table->timestamp('updated_at');

            $table->unique('symbol');
        });

        // API keys for external services
        Schema::create('api_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('service'); // blockchair, etherscan, etc.
            $table->string('api_key');
            $table->string('endpoint');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_configurations');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('wallet_addresses');
        Schema::dropIfExists('cryptocurrencies');
    }
};
EOF

echo "✅ Created crypto payment tables migration"

# Add User model extensions to existing users table
cat > database/migrations/2025_01_01_000002_add_user_wallet_fields.php << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_key')->unique()->nullable()->after('password');
            $table->string('webhook_url')->nullable()->after('api_key');
            $table->boolean('webhook_enabled')->default(false)->after('webhook_url');
            $table->json('webhook_events')->nullable()->after('webhook_enabled');
        });

        // User master wallets (one per user, stores encrypted mnemonic)
        Schema::create('user_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('encrypted_mnemonic'); // BIP39 24-word mnemonic
            $table->string('master_public_key'); // Master extended public key
            $table->timestamp('created_at');
            
            $table->unique('user_id'); // One wallet per user
        });

        // Generated addresses for each cryptocurrency
        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_wallet_id')->constrained()->onDelete('cascade');
            $table->foreignId('cryptocurrency_id')->constrained()->onDelete('cascade');
            $table->string('address')->unique();
            $table->string('derivation_path'); // e.g., "m/44'/0'/0'/0/0"
            $table->integer('address_index'); // Sequential index for this currency
            $table->string('public_key')->nullable();
            $table->text('encrypted_private_key')->nullable(); // Only if needed for sending
            $table->decimal('balance', 20, 8)->default(0);
            $table->timestamp('last_balance_check')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('label')->nullable(); // User-defined label
            $table->timestamps();

            $table->index(['user_id', 'cryptocurrency_id']);
            $table->index(['address']);
            $table->unique(['user_id', 'cryptocurrency_id', 'address_index']);
        });

        // Incoming transactions detected for user addresses
        Schema::create('user_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_address_id')->constrained()->onDelete('cascade');
            $table->foreignId('cryptocurrency_id')->constrained()->onDelete('cascade');
            $table->string('txid')->unique();
            $table->string('from_address')->nullable();
            $table->string('to_address'); // Our user's address
            $table->decimal('amount', 20, 8);
            $table->decimal('amount_usd', 20, 2)->nullable();
            $table->integer('confirmations')->default(0);
            $table->integer('required_confirmations')->default(3);
            $table->enum('status', ['pending', 'confirmed', 'failed'])->default('pending');
            $table->string('block_hash')->nullable();
            $table->bigInteger('block_height')->nullable();
            $table->timestamp('block_time')->nullable();
            $table->decimal('fee', 20, 8)->nullable();
            $table->json('raw_transaction')->nullable(); // Store full transaction data
            $table->boolean('webhook_sent')->default(false);
            $table->timestamp('webhook_sent_at')->nullable();
            $table->integer('webhook_attempts')->default(0);
            $table->text('webhook_last_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_address_id', 'status']);
            $table->index(['txid']);
        });

        // Address monitoring jobs queue
        Schema::create('address_monitoring_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_address_id')->constrained()->onDelete('cascade');
            $table->foreignId('cryptocurrency_id')->constrained()->onDelete('cascade');
            $table->string('address');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_block_hash')->nullable();
            $table->bigInteger('last_block_height')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'last_checked_at']);
            $table->unique(['user_address_id', 'cryptocurrency_id']);
        });

        // Update payment_transactions table to link with users
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->foreignId('user_address_id')->nullable()->after('user_id')->constrained()->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['user_address_id']);
            $table->dropColumn(['user_id', 'user_address_id']);
        });

        Schema::dropIfExists('address_monitoring_jobs');
        Schema::dropIfExists('user_transactions');
        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('user_wallets');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'webhook_url', 'webhook_enabled', 'webhook_events']);
        });
    }
};
EOF

echo "✅ Created user wallet tables migration"

# Create configuration file
cat > config/crypto.php << 'EOF'
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
];
EOF

echo "✅ Created crypto configuration file"

echo ""
echo "🎉 Basic structure and configuration created!"
echo ""
echo "📋 Next steps:"
echo "1. Copy this folder to your Laravel project"
echo "2. Copy all the model, service, and controller files from the artifacts"
echo "3. Run: php artisan migrate"
echo "4. Run: php artisan db:seed --class=CryptocurrencySeeder"
echo ""
echo "📁 Files created:"
echo "   - database/migrations/ (2 files)"
echo "   - config/crypto.php"
echo ""
echo "📝 Still need to create manually:"
echo "   - All Model files (9 files)"
echo "   - All Service files (6 files)"
echo "   - All Controller files (4 files)"
echo "   - All Command files (5 files)"
echo "   - Blockchain services (8 files)"
echo "   - Routes and other files"
echo ""
echo "💡 Use the artifacts provided by Claude to copy the remaining file contents!"
EOF