<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class UserWallet extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'encrypted_mnemonic',
        'master_public_key',
        'created_at'
    ];

    protected $casts = [
        'created_at' => 'datetime'
    ];

    protected $hidden = [
        'encrypted_mnemonic'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userAddresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Set encrypted mnemonic
     */
    public function setEncryptedMnemonicAttribute($value): void
    {
        $this->attributes['encrypted_mnemonic'] = Crypt::encryptString($value);
    }

    /**
     * Get decrypted mnemonic
     */
    public function getDecryptedMnemonic(): string
    {
        return Crypt::decryptString($this->encrypted_mnemonic);
    }

    /**
     * Check if wallet has addresses for cryptocurrency
     */
    public function hasAddressesFor(string $symbol): bool
    {
        return $this->userAddresses()
            ->whereHas('cryptocurrency', function ($query) use ($symbol) {
                $query->where('symbol', strtoupper($symbol));
            })
            ->exists();
    }
}

class UserAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_wallet_id',
        'cryptocurrency_id',
        'address',
        'derivation_path',
        'address_index',
        'public_key',
        'encrypted_private_key',
        'balance',
        'last_balance_check',
        'is_active',
        'label'
    ];

    protected $casts = [
        'balance' => 'decimal:8',
        'last_balance_check' => 'datetime',
        'is_active' => 'boolean',
        'address_index' => 'integer'
    ];

    protected $hidden = [
        'encrypted_private_key'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userWallet(): BelongsTo
    {
        return $this->belongsTo(UserWallet::class);
    }

    public function cryptocurrency(): BelongsTo
    {
        return $this->belongsTo(Cryptocurrency::class);
    }

    public function userTransactions(): HasMany
    {
        return $this->hasMany(UserTransaction::class);
    }

    public function monitoringJob(): HasMany
    {
        return $this->hasMany(AddressMonitoringJob::class);
    }

    /**
     * Set encrypted private key
     */
    public function setEncryptedPrivateKeyAttribute($value): void
    {
        if ($value) {
            $this->attributes['encrypted_private_key'] = Crypt::encryptString($value);
        }
    }

    /**
     * Get decrypted private key
     */
    public function getDecryptedPrivateKey(): ?string
    {
        if ($this->encrypted_private_key) {
            return Crypt::decryptString($this->encrypted_private_key);
        }
        return null;
    }

    /**
     * Update balance
     */
    public function updateBalance(float $newBalance): void
    {
        $this->update([
            'balance' => $newBalance,
            'last_balance_check' => now()
        ]);
    }

    /**
     * Get pending transactions
     */
    public function getPendingTransactions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->userTransactions()
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get confirmed transactions
     */
    public function getConfirmedTransactions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->userTransactions()
            ->where('status', 'confirmed')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}

class UserTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'user_address_id',
        'cryptocurrency_id',
        'txid',
        'from_address',
        'to_address',
        'amount',
        'amount_usd',
        'confirmations',
        'required_confirmations',
        'status',
        'block_hash',
        'block_height',
        'block_time',
        'fee',
        'raw_transaction',
        'webhook_sent',
        'webhook_sent_at',
        'webhook_attempts',
        'webhook_last_error'
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'amount_usd' => 'decimal:2',
        'fee' => 'decimal:8',
        'confirmations' => 'integer',
        'required_confirmations' => 'integer',
        'block_height' => 'integer',
        'block_time' => 'datetime',
        'raw_transaction' => 'array',
        'webhook_sent' => 'boolean',
        'webhook_sent_at' => 'datetime',
        'webhook_attempts' => 'integer'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function userAddress(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class);
    }

    public function cryptocurrency(): BelongsTo
    {
        return $this->belongsTo(Cryptocurrency::class);
    }

    /**
     * Check if transaction is confirmed
     */
    public function isConfirmed(): bool
    {
        return $this->confirmations >= $this->required_confirmations;
    }

    /**
     * Mark as confirmed
     */
    public function markAsConfirmed(): void
    {
        $this->update(['status' => 'confirmed']);
    }

    /**
     * Update confirmations
     */
    public function updateConfirmations(int $confirmations): void
    {
        $this->update(['confirmations' => $confirmations]);
        
        if ($this->isConfirmed() && $this->status === 'pending') {
            $this->markAsConfirmed();
        }
    }

    /**
     * Mark webhook as sent
     */
    public function markWebhookSent(): void
    {
        $this->update([
            'webhook_sent' => true,
            'webhook_sent_at' => now()
        ]);
    }

    /**
     * Record webhook attempt
     */
    public function recordWebhookAttempt(string $error = null): void
    {
        $this->increment('webhook_attempts');
        
        if ($error) {
            $this->update(['webhook_last_error' => $error]);
        }
    }

    /**
     * Scope for pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for confirmed transactions
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope for transactions needing webhook
     */
    public function scopeNeedsWebhook($query)
    {
        return $query->where('webhook_sent', false)
                    ->where('webhook_attempts', '<', 5); // Max 5 attempts
    }
}

class AddressMonitoringJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_address_id',
        'cryptocurrency_id',
        'address',
        'last_checked_at',
        'last_block_hash',
        'last_block_height',
        'is_active'
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'last_block_height' => 'integer',
        'is_active' => 'boolean'
    ];

    public function userAddress(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class);
    }

    public function cryptocurrency(): BelongsTo
    {
        return $this->belongsTo(Cryptocurrency::class);
    }

    /**
     * Mark as checked
     */
    public function markAsChecked(string $blockHash = null, int $blockHeight = null): void
    {
        $this->update([
            'last_checked_at' => now(),
            'last_block_hash' => $blockHash,
            'last_block_height' => $blockHeight
        ]);
    }

    /**
     * Scope for active monitoring jobs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for jobs that need checking
     */
    public function scopeNeedsCheck($query, int $intervalMinutes = 5)
    {
        return $query->where('is_active', true)
                    ->where(function ($query) use ($intervalMinutes) {
                        $query->whereNull('last_checked_at')
                             ->orWhere('last_checked_at', '<', now()->subMinutes($intervalMinutes));
                    });
    }
}