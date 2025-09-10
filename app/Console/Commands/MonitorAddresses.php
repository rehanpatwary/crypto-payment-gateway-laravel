<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AddressMonitoringService;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Log;

class MonitorAddresses extends Command
{
    protected $signature = 'crypto:monitor-addresses 
                           {--limit=100 : Maximum number of addresses to check}
                           {--timeout=600 : Maximum execution time in seconds}
                           {--user= : Monitor addresses for specific user ID}';

    protected $description = 'Monitor user addresses for new incoming transactions';

    protected $monitoringService;

    public function __construct(AddressMonitoringService $monitoringService)
    {
        parent::__construct();
        $this->monitoringService = $monitoringService;
    }

    public function handle(): int
    {
        $startTime = time();
        $limit = (int) $this->option('limit');
        $timeout = (int) $this->option('timeout');
        $userId = $this->option('user');

        $this->info("Starting address monitoring...");
        $this->info("Limit: {$limit} addresses");
        $this->info("Timeout: {$timeout} seconds");
        
        if ($userId) {
            $this->info("Monitoring addresses for user ID: {$userId}");
        }

        try {
            if ($userId) {
                $results = $this->monitoringService->monitorUserAddresses((int) $userId);
            } else {
                $results = $this->monitoringService->monitorAllAddresses($limit);
            }

            $duration = time() - $startTime;

            $this->info("\n📊 Monitoring Results:");
            $this->info("Addresses checked: {$results['checked']}");
            $this->info("New transactions found: {$results['new_transactions']}");
            $this->info("Updated transactions: {$results['updated_transactions']}");
            $this->info("Errors encountered: {$results['errors']}");
            $this->info("Duration: {$duration} seconds");

            if ($results['errors'] > 0) {
                $this->warn("⚠️  Some errors occurred during monitoring. Check logs for details.");
            }

            if ($results['new_transactions'] > 0) {
                $this->info("🎉 Found {$results['new_transactions']} new transactions!");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Address monitoring failed: " . $e->getMessage());
            Log::error("Address monitoring command failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}

class RetryWebhooks extends Command
{
    protected $signature = 'crypto:retry-webhooks 
                           {--limit=50 : Maximum number of webhooks to retry}';

    protected $description = 'Retry failed webhook deliveries';

    protected $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        parent::__construct();
        $this->webhookService = $webhookService;
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        
        $this->info("Retrying failed webhooks...");
        $this->info("Limit: {$limit} webhooks");

        try {
            $results = $this->webhookService->retryFailedWebhooks();

            $this->info("\n📊 Webhook Retry Results:");
            $this->info("Webhooks retried: {$results['retried']}");
            $this->info("Successful deliveries: {$results['succeeded']}");
            $this->info("Failed deliveries: {$results['failed']}");

            if ($results['failed'] > 0) {
                $this->warn("⚠️  {$results['failed']} webhooks still failed after retry.");
            }

            if ($results['succeeded'] > 0) {
                $this->info("✅ Successfully delivered {$results['succeeded']} webhooks!");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Webhook retry failed: " . $e->getMessage());
            Log::error("Webhook retry command failed", [
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
}

class CreateUserWallet extends Command
{
    protected $signature = 'crypto:create-wallet 
                           {user : User ID or email}
                           {--force : Force create wallet even if one exists}';

    protected $description = 'Create a new wallet for a user';

    protected $walletService;

    public function __construct(\App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->walletService = $walletService;
    }

    public function handle(): int
    {
        $userIdentifier = $this->argument('user');
        $force = $this->option('force');

        try {
            // Find user by ID or email
            $user = \App\Models\User::where('id', $userIdentifier)
                ->orWhere('email', $userIdentifier)
                ->first();

            if (!$user) {
                $this->error("❌ User not found: {$userIdentifier}");
                return 1;
            }

            $this->info("Found user: {$user->name} ({$user->email})");

            // Check if wallet already exists
            if ($user->userWallet && !$force) {
                $this->warn("⚠️  User already has a wallet. Use --force to recreate.");
                return 1;
            }

            if ($user->userWallet && $force) {
                $this->warn("🗑️  Deleting existing wallet...");
                $user->userWallet->delete();
            }

            // Create wallet
            $this->info("🔐 Creating new wallet...");
            $wallet = $this->walletService->createWalletForUser($user);

            $this->info("✅ Wallet created successfully!");
            $this->info("Wallet ID: {$wallet->id}");
            $this->info("Created at: {$wallet->created_at}");

            // Show generated addresses
            $addresses = $user->userAddresses()->with('cryptocurrency')->get();
            $this->info("\n📍 Generated addresses:");
            
            foreach ($addresses as $address) {
                $this->line("  {$address->cryptocurrency->symbol}: {$address->address}");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Failed to create wallet: " . $e->getMessage());
            Log::error("Create wallet command failed", [
                'user' => $userIdentifier,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
}

class GenerateAddress extends Command
{
    protected $signature = 'crypto:generate-address 
                           {user : User ID or email}
                           {cryptocurrency : Cryptocurrency symbol (BTC, ETH, etc.)}
                           {--label= : Optional label for the address}
                           {--count=1 : Number of addresses to generate}';

    protected $description = 'Generate new address(es) for a user';

    protected $walletService;

    public function __construct(\App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->walletService = $walletService;
    }

    public function handle(): int
    {
        $userIdentifier = $this->argument('user');
        $cryptocurrency = strtoupper($this->argument('cryptocurrency'));
        $label = $this->option('label');
        $count = (int) $this->option('count');

        try {
            // Find user
            $user = \App\Models\User::where('id', $userIdentifier)
                ->orWhere('email', $userIdentifier)
                ->first();

            if (!$user) {
                $this->error("❌ User not found: {$userIdentifier}");
                return 1;
            }

            // Verify cryptocurrency exists
            $crypto = \App\Models\Cryptocurrency::where('symbol', $cryptocurrency)
                ->where('is_active', true)
                ->first();

            if (!$crypto) {
                $this->error("❌ Cryptocurrency not found or inactive: {$cryptocurrency}");
                return 1;
            }

            $this->info("Generating {$count} address(es) for {$user->name}...");
            $this->info("Cryptocurrency: {$crypto->name} ({$crypto->symbol})");
            
            if ($label) {
                $this->info("Label: {$label}");
            }

            $addresses = [];
            
            for ($i = 0; $i < $count; $i++) {
                $addressLabel = $label;
                if ($count > 1) {
                    $addressLabel = $label ? "{$label} #{$i + 1}" : "Address #{$i + 1}";
                }
                
                $address = $this->walletService->generateAddress($user, $cryptocurrency, $addressLabel);
                $addresses[] = $address;
                
                $this->info("✅ Generated: {$address->address}");
                $this->line("   Index: {$address->address_index}");
                $this->line("   Path: {$address->derivation_path}");
                if ($address->label) {
                    $this->line("   Label: {$address->label}");
                }
                $this->line("");
            }

            $this->info("🎉 Successfully generated {$count} address(es)!");

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Failed to generate address: " . $e->getMessage());
            Log::error("Generate address command failed", [
                'user' => $userIdentifier,
                'cryptocurrency' => $cryptocurrency,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
}

class WalletStats extends Command
{
    protected $signature = 'crypto:wallet-stats 
                           {--user= : Show stats for specific user}
                           {--detailed : Show detailed breakdown}';

    protected $description = 'Show wallet and transaction statistics';

    public function handle(): int
    {
        $userId = $this->option('user');
        $detailed = $this->option('detailed');

        try {
            if ($userId) {
                $this->showUserStats((int) $userId, $detailed);
            } else {
                $this->showGlobalStats($detailed);
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Failed to fetch stats: " . $e->getMessage());
            return 1;
        }
    }

    protected function showGlobalStats(bool $detailed): void
    {
        $this->info("🌍 Global Wallet Statistics");
        $this->line("========================");

        // Basic stats
        $totalUsers = \App\Models\User::count();
        $usersWithWallets = \App\Models\User::has('userWallet')->count();
        $totalAddresses = \App\Models\UserAddress::where('is_active', true)->count();
        $totalTransactions = \App\Models\UserTransaction::count();
        $pendingTransactions = \App\Models\UserTransaction::where('status', 'pending')->count();
        $confirmedTransactions = \App\Models\UserTransaction::where('status', 'confirmed')->count();

        $this->info("Users: {$totalUsers}");
        $this->info("Users with wallets: {$usersWithWallets}");
        $this->info("Total addresses: {$totalAddresses}");
        $this->info("Total transactions: {$totalTransactions}");
        $this->info("Pending transactions: {$pendingTransactions}");
        $this->info("Confirmed transactions: {$confirmedTransactions}");

        if ($detailed) {
            $this->line("\n📊 Breakdown by Cryptocurrency:");
            
            $cryptos = \App\Models\Cryptocurrency::active()->get();
            foreach ($cryptos as $crypto) {
                $addressCount = \App\Models\UserAddress::where('cryptocurrency_id', $crypto->id)
                    ->where('is_active', true)->count();
                $txCount = \App\Models\UserTransaction::where('cryptocurrency_id', $crypto->id)->count();
                $totalBalance = \App\Models\UserAddress::where('cryptocurrency_id', $crypto->id)
                    ->where('is_active', true)->sum('balance');

                $this->line("  {$crypto->symbol}: {$addressCount} addresses, {$txCount} transactions, {$totalBalance} total balance");
            }
        }
    }

    protected function showUserStats(int $userId, bool $detailed): void
    {
        $user = \App\Models\User::with('userWallet')->findOrFail($userId);
        
        $this->info("👤 Wallet Statistics for {$user->name}");
        $this->line("=====================================");

        if (!$user->userWallet) {
            $this->warn("❌ User has no wallet");
            return;
        }

        $addressCount = $user->userAddresses()->where('is_active', true)->count();
        $transactionCount = $user->userTransactions()->count();
        $pendingCount = $user->userTransactions()->where('status', 'pending')->count();
        $confirmedCount = $user->userTransactions()->where('status', 'confirmed')->count();

        $this->info("Wallet created: {$user->userWallet->created_at}");
        $this->info("Total addresses: {$addressCount}");
        $this->info("Total transactions: {$transactionCount}");
        $this->info("Pending: {$pendingCount}");
        $this->info("Confirmed: {$confirmedCount}");

        if ($detailed) {
            $balances = $user->getAllBalances();
            
            $this->line("\n💰 Balances:");
            foreach ($balances as $balance) {
                $this->line("  {$balance['symbol']}: {$balance['balance']} ({$balance['address_count']} addresses)");
            }

            $recentTx = $user->getRecentTransactions(5);
            if ($recentTx->count() > 0) {
                $this->line("\n📋 Recent Transactions:");
                foreach ($recentTx as $tx) {
                    $this->line("  {$tx->txid}: {$tx->amount} {$tx->cryptocurrency->symbol} ({$tx->status})");
                }
            }
        }
    }
}