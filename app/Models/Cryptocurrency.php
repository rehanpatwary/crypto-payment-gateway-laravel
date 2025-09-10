<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cryptocurrency extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'name',
        'network',
        'min_amount',
        'max_amount',
        'fee_percentage',
        'fixed_fee',
        'is_active',
        'is_token',
        'contract_address',
        'decimals',
        'rpc_url'
    ];

    protected $casts = [
        'min_amount' => 'decimal:8',
        'max_amount' => 'decimal:8',
        'fee_percentage' => 'decimal:4',
        'fixed_fee' => 'decimal:8',
        'is_active' => 'boolean',
        'is_token' => 'boolean',
        'decimals' => 'integer'
    ];

    public function userAddresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function userTransactions(): HasMany
    {
        return $this->hasMany(UserTransaction::class);
    }

    public function isValidAmount(float $amount): bool
    {
        if ($amount < $this->min_amount) {
            return false;
        }

        if ($this->max_amount && $amount > $this->max_amount) {
            return false;
        }

        return true;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
