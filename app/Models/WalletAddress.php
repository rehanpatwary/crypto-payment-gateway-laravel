<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class WalletAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'cryptocurrency_id',
        'address',
        'private_key',
        'is_active',
        'last_used_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime'
    ];

    protected $hidden = [
        'private_key'
    ];

    public function cryptocurrency(): BelongsTo
    {
        return $this->belongsTo(Cryptocurrency::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function setPrivateKeyAttribute($value): void
    {
        if ($value) {
            $this->attributes['private_key'] = Crypt::encryptString($value);
        }
    }

    public function getPrivateKeyAttribute($value): ?string
    {
        if ($value) {
            return Crypt::decryptString($value);
        }
        return null;
    }

    public function markAsUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCryptocurrency($query, $cryptocurrencyId)
    {
        return $query->where('cryptocurrency_id', $cryptocurrencyId);
    }
}