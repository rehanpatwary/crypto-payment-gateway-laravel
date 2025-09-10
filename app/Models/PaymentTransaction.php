<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'cryptocurrency_id',
        'wallet_address_id',
        'amount',
        'amount_usd',
        'from_address',
        'to_address',
        'blockchain_tx_hash',
        'status',
        'confirmations',
        'required_confirmations',
        'expires_at',
        'metadata',
        'callback_url',
        'callback_sent'
    ];

    protected $casts = [
        'amount' => 'decimal:8',
        'amount_usd' => 'decimal:2',
        'confirmations' => 'integer',
        'required_confirmations' => 'integer',
        'expires_at' => 'datetime',
        'metadata' => 'array',
        'callback_sent' => 'boolean'
    ];

    public function cryptocurrency(): BelongsTo
    {
        return $this->belongsTo(Cryptocurrency::class);
    }

    public function walletAddress(): BelongsTo
    {
        return $this->belongsTo(WalletAddress::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at < now();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmations >= $this->required_confirmations;
    }

    public function markAsConfirmed(): void
    {
        $this->update(['status' => 'confirmed']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'pending')
                    ->where('expires_at', '<', now());
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_id)) {
                $transaction->transaction_id = 'TX_' . Str::random(16);
            }
        });
    }

    public function getQrCodeData(): string
    {
        $symbol = strtolower($this->cryptocurrency->symbol);
        
        switch ($symbol) {
            case 'btc':
            case 'ltc':
                return "{$symbol}:{$this->to_address}?amount={$this->amount}";
            
            case 'eth':
            case 'sol':
                return $this->to_address;
            
            case 'xmr':
                return "monero:{$this->to_address}?tx_amount={$this->amount}";
            
            default:
                return $this->to_address;
        }
    }
}