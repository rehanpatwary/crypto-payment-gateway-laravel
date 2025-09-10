<?php

namespace App\Services;

use App\Models\UserTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    protected $timeout;
    protected $maxRetries;

    public function __construct()
    {
        $this->timeout = config('crypto.callback_timeout', 30);
        $this->maxRetries = config('crypto.max_callback_retries', 3);
    }

    /**
     * Send transaction webhook notification
     */
    public function sendTransactionWebhook(UserTransaction $transaction, string $event): bool
    {
        $user = $transaction->userAddress->user;
        
        if (!$user->webhook_url || !$user->webhook_enabled) {
            return false;
        }

        if (!in_array($event, $user->webhook_events ?? [])) {
            return false;
        }

        try {
            $payload = $this->buildTransactionPayload($transaction, $event);
            $success = $this->sendWebhook($user->webhook_url, $payload, $transaction);
            
            if ($success) {
                $transaction->markWebhookSent();
                
                Log::info("Transaction webhook sent successfully", [
                    'user_id' => $user->id,
                    'transaction_id' => $transaction->id,
                    'event' => $event,
                    'webhook_url' => $user->webhook_url
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::error("Transaction webhook failed", [
                'user_id' => $user->id,
                'transaction_id' => $transaction->id,
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            
            $transaction->recordWebhookAttempt($e->getMessage());
            return false;
        }
    }

    /**
     * Send webhook with retry logic
     */
    protected function sendWebhook(string $url, array $payload, UserTransaction $transaction = null): bool
    {
        $lastError = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'CryptoGateway-Webhook/1.0',
                        'X-Webhook-Signature' => $this->generateSignature($payload),
                        'X-Webhook-Event' => $payload['event'],
                        'X-Webhook-Attempt' => $attempt
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    Log::info("Webhook sent successfully", [
                        'url' => $url,
                        'attempt' => $attempt,
                        'status' => $response->status()
                    ]);
                    return true;
                }
                
                $lastError = "HTTP {$response->status()}: {$response->body()}";
                
                Log::warning("Webhook attempt failed", [
                    'url' => $url,
                    'attempt' => $attempt,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                
                Log::warning("Webhook attempt error", [
                    'url' => $url,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
            }
            
            // Wait before retry (exponential backoff)
            if ($attempt < $this->maxRetries) {
                sleep(pow(2, $attempt - 1)); // 1s, 2s, 4s delays
            }
        }
        
        // Record final failure
        if ($transaction) {
            $transaction->recordWebhookAttempt($lastError);
        }
        
        Log::error("Webhook failed after all attempts", [
            'url' => $url,
            'attempts' => $this->maxRetries,
            'last_error' => $lastError
        ]);
        
        return false;
    }

    /**
     * Build transaction webhook payload
     */
    protected function buildTransactionPayload(UserTransaction $transaction, string $event): array
    {
        return [
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'data' => [
                'transaction_id' => $transaction->id,
                'txid' => $transaction->txid,
                'user_id' => $transaction->user_id,
                'cryptocurrency' => [
                    'symbol' => $transaction->cryptocurrency->symbol,
                    'name' => $transaction->cryptocurrency->name,
                    'is_token' => $transaction->cryptocurrency->is_token,
                    'contract_address' => $transaction->cryptocurrency->contract_address
                ],
                'address' => [
                    'address' => $transaction->to_address,
                    'label' => $transaction->userAddress->label,
                    'derivation_path' => $transaction->userAddress->derivation_path
                ],
                'amount' => [
                    'value' => $transaction->amount,
                    'currency' => $transaction->cryptocurrency->symbol,
                    'usd_value' => $transaction->amount_usd
                ],
                'transaction' => [
                    'from_address' => $transaction->from_address,
                    'confirmations' => $transaction->confirmations,
                    'required_confirmations' => $transaction->required_confirmations,
                    'status' => $transaction->status,
                    'block_hash' => $transaction->block_hash,
                    'block_height' => $transaction->block_height,
                    'block_time' => $transaction->block_time?->toISOString(),
                    'fee' => $transaction->fee
                ],
                'created_at' => $transaction->created_at->toISOString(),
                'updated_at' => $transaction->updated_at->toISOString()
            ]
        ];
    }

    /**
     * Generate webhook signature for verification
     */
    protected function generateSignature(array $payload): string
    {
        $secret = config('app.key');
        $data = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return hash_hmac('sha256', $data, $secret);
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = config('app.key');
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Send test webhook
     */
    public function sendTestWebhook(User $user): bool
    {
        if (!$user->webhook_url) {
            return false;
        }

        $payload = [
            'event' => 'webhook_test',
            'timestamp' => now()->toISOString(),
            'data' => [
                'user_id' => $user->id,
                'message' => 'This is a test webhook from your crypto payment gateway',
                'webhook_url' => $user->webhook_url,
                'configured_events' => $user->webhook_events ?? []
            ]
        ];

        try {
            return $this->sendWebhook($user->webhook_url, $payload);
        } catch (\Exception $e) {
            Log::error("Test webhook failed", [
                'user_id' => $user->id,
                'webhook_url' => $user->webhook_url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Retry failed webhooks
     */
    public function retryFailedWebhooks(): array
    {
        $results = ['retried' => 0, 'succeeded' => 0, 'failed' => 0];
        
        // Get transactions that need webhook retry
        $transactions = UserTransaction::needsWebhook()
            ->with(['userAddress.user', 'cryptocurrency'])
            ->limit(50)
            ->get();

        foreach ($transactions as $transaction) {
            $user = $transaction->userAddress->user;
            
            if (!$user->webhook_enabled || !$user->webhook_url) {
                continue;
            }

            $results['retried']++;
            
            // Determine event type based on transaction status
            $event = $transaction->status === 'confirmed' ? 'payment_confirmed' : 'payment_received';
            
            $success = $this->sendTransactionWebhook($transaction, $event);
            
            if ($success) {
                $results['succeeded']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get webhook delivery statistics for user
     */
    public function getWebhookStats(User $user): array
    {
        $totalTransactions = $user->userTransactions()->count();
        $webhooksSent = $user->userTransactions()->where('webhook_sent', true)->count();
        $webhooksFailed = $user->userTransactions()
            ->where('webhook_sent', false)
            ->where('webhook_attempts', '>', 0)
            ->count();

        return [
            'total_transactions' => $totalTransactions,
            'webhooks_sent' => $webhooksSent,
            'webhooks_failed' => $webhooksFailed,
            'success_rate' => $totalTransactions > 0 ? round(($webhooksSent / $totalTransactions) * 100, 2) : 0,
            'last_webhook_sent' => $user->userTransactions()
                ->where('webhook_sent', true)
                ->orderBy('webhook_sent_at', 'desc')
                ->value('webhook_sent_at')
        ];
    }

    /**
     * Send balance update webhook
     */
    public function sendBalanceUpdateWebhook(User $user, string $cryptocurrency, float $oldBalance, float $newBalance): bool
    {
        if (!$user->hasWebhookForEvent('balance_update')) {
            return false;
        }

        $payload = [
            'event' => 'balance_update',
            'timestamp' => now()->toISOString(),
            'data' => [
                'user_id' => $user->id,
                'cryptocurrency' => $cryptocurrency,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'change' => $newBalance - $oldBalance
            ]
        ];

        try {
            return $this->sendWebhook($user->webhook_url, $payload);
        } catch (\Exception $e) {
            Log::error("Balance update webhook failed", [
                'user_id' => $user->id,
                'cryptocurrency' => $cryptocurrency,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send address generation webhook
     */
    public function sendAddressGeneratedWebhook(User $user, $userAddress): bool
    {
        if (!$user->hasWebhookForEvent('address_generated')) {
            return false;
        }

        $payload = [
            'event' => 'address_generated',
            'timestamp' => now()->toISOString(),
            'data' => [
                'user_id' => $user->id,
                'address' => [
                    'address' => $userAddress->address,
                    'cryptocurrency' => $userAddress->cryptocurrency->symbol,
                    'derivation_path' => $userAddress->derivation_path,
                    'address_index' => $userAddress->address_index,
                    'label' => $userAddress->label
                ]
            ]
        ];

        try {
            return $this->sendWebhook($user->webhook_url, $payload);
        } catch (\Exception $e) {
            Log::error("Address generated webhook failed", [
                'user_id' => $user->id,
                'address' => $userAddress->address,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}