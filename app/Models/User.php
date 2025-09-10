<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'api_key',
        'webhook_url',
        'webhook_enabled',
        'webhook_events'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'webhook_enabled' => 'boolean',
        'webhook_events' => 'array'
    ];

    public function userWallet(): HasOne
    {
        return $this->hasOne(UserWallet::class);
    }

    public function userAddresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function userTransactions(): HasMany
    {
        return $this->hasMany(UserTransaction::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Generate API key for user
     */
    public function generateApiKey(): string
    {
        $apiKey = 'pk_' . Str::random(32);
        $this->update(['api_key' => $apiKey]);
        return $apiKey;
    }

    /**
     * Get addresses for specific cryptocurrency
     */
    public function getAddressesForCryptocurrency(string $symbol): \Illuminate\Database\Eloquent\Collection
    {
        return $this->userAddresses()
            ->whereHas('cryptocurrency', function ($query) use ($symbol) {
                $query->where('symbol', strtoupper($symbol));
            })
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get total balance for specific cryptocurrency
     */
    public function getTotalBalance(string $symbol): float
    {
        return $this->userAddresses()
            ->whereHas('cryptocurrency', function ($query) use ($symbol) {
                $query->where('symbol', strtoupper($symbol));
            })
            ->where('is_active', true)
            ->sum('balance');
    }

    /**
     * Get all balances for user
     */
    public function getAllBalances(): array
    {
        $balances = [];
        
        $addresses = $this->userAddresses()
            ->with('cryptocurrency')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->get();

        foreach ($addresses as $address) {
            $symbol = $address->cryptocurrency->symbol;
            if (!isset($balances[$symbol])) {
                $balances[$symbol] = [
                    'symbol' => $symbol,
                    'name' => $address->cryptocurrency->name,
                    'balance' => 0,
                    'address_count' => 0
                ];
            }
            $balances[$symbol]['balance'] += $address->balance;
            $balances[$symbol]['address_count']++;
        }

        return array_values($balances);
    }

    /**
     * Check if webhook is configured for specific event
     */
    public function hasWebhookForEvent(string $event): bool
    {
        return $this->webhook_enabled && 
               $this->webhook_url && 
               in_array($event, $this->webhook_events ?? []);
    }

    /**
     * Get pending transactions
     */
    public function getPendingTransactions(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->userTransactions()
            ->with(['cryptocurrency', 'userAddress'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get recent transactions
     */
    public function getRecentTransactions(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $this->userTransactions()
            ->with(['cryptocurrency', 'userAddress'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Boot method to automatically create wallet when user is created
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            // Generate API key
            $user->generateApiKey();
            
            // Create wallet will be handled by WalletService
        });
    }
}