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
