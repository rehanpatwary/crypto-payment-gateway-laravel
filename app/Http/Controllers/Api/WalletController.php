<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use App\Services\AddressMonitoringService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    protected $walletService;
    protected $monitoringService;
    protected $webhookService;

    public function __construct(
        WalletService $walletService,
        AddressMonitoringService $monitoringService,
        WebhookService $webhookService
    ) {
        $this->walletService = $walletService;
        $this->monitoringService = $monitoringService;
        $this->webhookService = $webhookService;
    }

    /**
     * Get user's wallet information
     */
    public function getWallet(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user->userWallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wallet not found. Create a wallet first.'
                ], 404);
            }

            $wallet = $user->userWallet;
            $balances = $user->getAllBalances();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'wallet_id' => $wallet->id,
                    'created_at' => $wallet->created_at->toISOString(),
                    'balances' => $balances,
                    'total_addresses' => $user->userAddresses()->where('is_active', true)->count(),
                    'recent_transactions' => $user->getRecentTransactions(5)->map(function ($tx) {
                        return [
                            'id' => $tx->id,
                            'txid' => $tx->txid,
                            'amount' => $tx->amount,
                            'currency' => $tx->cryptocurrency->symbol,
                            'status' => $tx->status,
                            'confirmations' => $tx->confirmations,
                            'created_at' => $tx->created_at->toISOString()
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching wallet", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch wallet information'
            ], 500);
        }
    }

    /**
     * Create a new wallet for user
     */
    public function createWallet(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if ($user->userWallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has a wallet'
                ], 400);
            }

            $wallet = $this->walletService->createWalletForUser($user);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'wallet_id' => $wallet->id,
                    'created_at' => $wallet->created_at->toISOString(),
                    'message' => 'Wallet created successfully with initial addresses for all supported cryptocurrencies'
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error("Error creating wallet", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create wallet'
            ], 500);
        }
    }

    /**
     * Generate new address for cryptocurrency
     */
    public function generateAddress(Request $request): JsonResponse
    {
        $request->validate([
            'cryptocurrency' => 'required|string|exists:cryptocurrencies,symbol',
            'label' => 'nullable|string|max:255'
        ]);

        try {
            $user = $request->user();
            $cryptocurrency = strtoupper($request->cryptocurrency);
            $label = $request->label;

            $address = $this->walletService->generateAddress($user, $cryptocurrency, $label);

            // Send webhook notification
            $this->webhookService->sendAddressGeneratedWebhook($user, $address);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $address->id,
                    'address' => $address->address,
                    'cryptocurrency' => $address->cryptocurrency->symbol,
                    'derivation_path' => $address->derivation_path,
                    'address_index' => $address->address_index,
                    'label' => $address->label,
                    'balance' => $address->balance,
                    'created_at' => $address->created_at->toISOString()
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("Error generating address", [
                'user_id' => $request->user()->id,
                'cryptocurrency' => $request->cryptocurrency,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate address'
            ], 500);
        }
    }

    /**
     * Get user's addresses
     */
    public function getAddresses(Request $request): JsonResponse
    {
        $request->validate([
            'cryptocurrency' => 'nullable|string|exists:cryptocurrencies,symbol',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $user = $request->user();
            $cryptocurrency = $request->cryptocurrency ? strtoupper($request->cryptocurrency) : null;
            $perPage = $request->per_page ?? 25;

            $query = $user->userAddresses()
                ->with('cryptocurrency')
                ->where('is_active', true);

            if ($cryptocurrency) {
                $query->whereHas('cryptocurrency', function ($q) use ($cryptocurrency) {
                    $q->where('symbol', $cryptocurrency);
                });
            }

            $addresses = $query->orderBy('address_index')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $addresses->items(),
                'pagination' => [
                    'current_page' => $addresses->currentPage(),
                    'last_page' => $addresses->lastPage(),
                    'per_page' => $addresses->perPage(),
                    'total' => $addresses->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching addresses", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch addresses'
            ], 500);
        }
    }

    /**
     * Get user's transactions
     */
    public function getTransactions(Request $request): JsonResponse
    {
        $request->validate([
            'cryptocurrency' => 'nullable|string|exists:cryptocurrencies,symbol',
            'status' => 'nullable|string|in:pending,confirmed,failed',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $user = $request->user();
            $perPage = $request->per_page ?? 25;

            $query = $user->userTransactions()
                ->with(['cryptocurrency', 'userAddress']);

            if ($request->cryptocurrency) {
                $query->whereHas('cryptocurrency', function ($q) use ($request) {
                    $q->where('symbol', strtoupper($request->cryptocurrency));
                });
            }

            if ($request->status) {
                $query->where('status', $request->status);
            }

            $transactions = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $transactions->items(),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching transactions", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transactions'
            ], 500);
        }
    }

    /**
     * Get specific transaction details
     */
    public function getTransaction(Request $request, int $transactionId): JsonResponse
    {
        try {
            $user = $request->user();
            
            $transaction = $user->userTransactions()
                ->with(['cryptocurrency', 'userAddress'])
                ->findOrFail($transactionId);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $transaction->id,
                    'txid' => $transaction->txid,
                    'from_address' => $transaction->from_address,
                    'to_address' => $transaction->to_address,
                    'amount' => $transaction->amount,
                    'amount_usd' => $transaction->amount_usd,
                    'cryptocurrency' => [
                        'symbol' => $transaction->cryptocurrency->symbol,
                        'name' => $transaction->cryptocurrency->name,
                        'is_token' => $transaction->cryptocurrency->is_token
                    ],
                    'address' => [
                        'address' => $transaction->userAddress->address,
                        'label' => $transaction->userAddress->label,
                        'derivation_path' => $transaction->userAddress->derivation_path
                    ],
                    'confirmations' => $transaction->confirmations,
                    'required_confirmations' => $transaction->required_confirmations,
                    'status' => $transaction->status,
                    'block_hash' => $transaction->block_hash,
                    'block_height' => $transaction->block_height,
                    'block_time' => $transaction->block_time?->toISOString(),
                    'fee' => $transaction->fee,
                    'webhook_sent' => $transaction->webhook_sent,
                    'created_at' => $transaction->created_at->toISOString(),
                    'updated_at' => $transaction->updated_at->toISOString()
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error("Error fetching transaction", [
                'user_id' => $request->user()->id,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transaction'
            ], 500);
        }
    }

    /**
     * Update address label
     */
    public function updateAddressLabel(Request $request, int $addressId): JsonResponse
    {
        $request->validate([
            'label' => 'required|string|max:255'
        ]);

        try {
            $user = $request->user();
            
            $address = $user->userAddresses()->findOrFail($addressId);
            $address->update(['label' => $request->label]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $address->id,
                    'label' => $address->label,
                    'updated_at' => $address->updated_at->toISOString()
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Address not found'
            ], 404);

        } catch (\Exception $e) {
            Log::error("Error updating address label", [
                'user_id' => $request->user()->id,
                'address_id' => $addressId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update address label'
            ], 500);
        }
    }

    /**
     * Force refresh address balances
     */
    public function refreshBalances(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Trigger monitoring for user's addresses
            $results = $this->monitoringService->monitorUserAddresses($user->id);

            return response()->json([
                'success' => true,
                'data' => [
                    'addresses_checked' => $results['checked'],
                    'new_transactions' => $results['new_transactions'],
                    'errors' => $results['errors'],
                    'message' => 'Balance refresh completed'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error refreshing balances", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh balances'
            ], 500);
        }
    }

    /**
     * Configure webhook settings
     */
    public function configureWebhook(Request $request): JsonResponse
    {
        $request->validate([
            'webhook_url' => 'required|url|max:500',
            'webhook_enabled' => 'required|boolean',
            'webhook_events' => 'required|array|min:1',
            'webhook_events.*' => 'string|in:payment_received,payment_confirmed,balance_update,address_generated'
        ]);

        try {
            $user = $request->user();
            
            $user->update([
                'webhook_url' => $request->webhook_url,
                'webhook_enabled' => $request->webhook_enabled,
                'webhook_events' => $request->webhook_events
            ]);

            // Send test webhook
            $testResult = $this->webhookService->sendTestWebhook($user);

            return response()->json([
                'success' => true,
                'data' => [
                    'webhook_url' => $user->webhook_url,
                    'webhook_enabled' => $user->webhook_enabled,
                    'webhook_events' => $user->webhook_events,
                    'test_webhook_sent' => $testResult,
                    'message' => 'Webhook configuration updated successfully'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Error configuring webhook", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to configure webhook'
            ], 500);
        }
    }

    /**
     * Get webhook statistics
     */
    public function getWebhookStats(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $stats = $this->webhookService->getWebhookStats($user);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("Error fetching webhook stats", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch webhook statistics'
            ], 500);
        }
    }
}