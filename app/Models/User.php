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

    public function generateApiKey(): string
    {
        $apiKey = 'pk_' . Str::random(32);
        $this->update(['api_key' => $apiKey]);
        return $apiKey;
    }

    public function hasWebhookForEvent(string $event): bool
    {
        return $this->webhook_enabled && 
               $this->webhook_url && 
               in_array($event, $this->webhook_events ?? []);
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($user) {
            $user->generateApiKey();
        });
    }
}
