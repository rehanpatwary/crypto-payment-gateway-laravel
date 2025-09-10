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