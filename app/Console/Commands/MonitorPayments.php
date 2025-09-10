<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PaymentTransaction;
use App\Services\CryptoPaymentService;
use Illuminate\Support\Facades\Log;

class MonitorPayments extends Command
{
    protected $signature = 'crypto:monitor-payments 
                           {--limit=50 : Maximum number of transactions to process}
                           {--timeout=300 : Maximum execution time in seconds}';

    protected $description = 'Monitor pending cryptocurrency payments and update their status';

    protected $paymentService;

    public function __construct(CryptoPaymentService $paymentService)
    {
        parent::__construct();
        $this->paymentService = $paymentService;
    }

    public function handle(): int
    {
        $startTime = time();
        $limit = (int) $this->option('limit');
        $timeout = (int) $this->option('timeout');

        $this->info("Starting payment monitoring...");
        $this->info("Limit: {$limit} transactions");
        $this->info("Timeout: {$timeout} seconds");

        // Get pending transactions
        $pendingTransactions = PaymentTransaction::pending()
            ->with(['cryptocurrency', 'walletAddress'])
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        if ($pendingTransactions->isEmpty()) {
            $this->info('No pending transactions to monitor.');
            return 0;
        }

        $this->info("Found {$pendingTransactions->count()} pending transactions to check.");

        $processed = 0;
        $confirmed = 0;
        $expired = 0;
        $errors = 0;

        foreach ($pendingTransactions as $transaction) {
            // Check timeout
            if (time() - $startTime > $timeout) {
                $this->warn("Timeout reached. Stopping monitoring.");
                break;
            }

            try {
                $this->line("Checking transaction: {$transaction->transaction_id} ({$transaction->cryptocurrency->symbol})");

                $originalStatus = $transaction->status;
                $updatedTransaction = $this->paymentService->checkPaymentStatus($transaction);

                if ($updatedTransaction->status !== $originalStatus) {
                    $this->info("  Status changed: {$originalStatus} → {$updatedTransaction->status}");
                    
                    if ($updatedTransaction->status === 'confirmed') {
                        $confirmed++;
                        $this->info("  ✅ Payment confirmed!");
                    } elseif ($updatedTransaction->status === 'expired') {
                        $expired++;
                        $this->warn("  ⏰ Payment expired");
                    }
                } else {
                    $this->line("  Status unchanged: {$updatedTransaction->status}");
                    
                    if ($updatedTransaction->blockchain_tx_hash) {
                        $this->line("  Confirmations: {$updatedTransaction->confirmations}/{$updatedTransaction->required_confirmations}");
                    } else {
                        $this->line("  No blockchain transaction found yet");
                    }
                }

                $processed++;

                // Small delay to avoid hitting API rate limits
                usleep(200000); // 200ms

            } catch (\Exception $e) {
                $errors++;
                $this->error("  ❌ Error checking transaction {$transaction->transaction_id}: " . $e->getMessage());
                
                Log::error("Payment monitoring error", [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Mark obviously expired transactions
        $this->markExpiredTransactions();

        $this->info("\n📊 Monitoring Summary:");
        $this->info("Processed: {$processed}");
        $this->info("Confirmed: {$confirmed}");
        $this->info("Expired: {$expired}");
        $this->info("Errors: {$errors}");
        $this->info("Duration: " . (time() - $startTime) . " seconds");

        return 0;
    }

    protected function markExpiredTransactions(): void
    {
        $expiredCount = PaymentTransaction::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired']);

        if ($expiredCount > 0) {
            $this->info("Marked {$expiredCount} transactions as expired.");
        }
    }
}