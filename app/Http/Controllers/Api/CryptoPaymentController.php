<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Services\CryptoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CryptoPaymentController extends Controller
{
    protected $paymentService;

    public function __construct(CryptoPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Get supported cryptocurrencies
     */
    public function getSupportedCurrencies(): JsonResponse
    {
        try {
            $currencies = $this->paymentService->getSupportedCurrencies();
            
            return response()->json([
                'success' => true,
                'data' => $currencies
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching supported currencies", ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch supported currencies'
            ], 500);
        }
    }

    /**
     * Create a new payment request
     */
    public function createPayment(CreatePaymentRequest $request): JsonResponse
    {
        try {
            $transaction = $this->paymentService->createPayment($request->validated());
            
            return response()->json([
                'success' => true,
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->cryptocurrency->symbol,
                    'to_address' => $transaction->to_address,
                    'qr_data' => $transaction->getQrCodeData(),
                    'expires_at' => $transaction->expires_at->toISOString(),
                    'status' => $transaction->status
                ]
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error("Error creating payment", [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment'
            ], 500);
        }
    }

    /**
     * Get payment status
     */
    public function getPaymentStatus(string $transactionId): JsonResponse
    {
        try {
            $status = $this->paymentService->getPaymentStatus($transactionId);
            
            return response()->json([
                'success' => true,
                'data' => $status
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error("Error fetching payment status", [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment status'
            ], 500);
        }
    }

    /**
     * Get exchange rates
     */
    public function getExchangeRates(Request $request): JsonResponse
    {
        try {
            $symbols = $request->get('symbols');
            if ($symbols) {
                $symbols = is_array($symbols) ? $symbols : explode(',', $symbols);
            }
            
            $exchangeRateService = app(\App\Services\ExchangeRateService::class);
            $rates = $exchangeRateService->getRates($symbols);
            
            return response()->json([
                'success' => true,
                'data' => $rates
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching exchange rates", ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch exchange rates'
            ], 500);
        }
    }

    /**
     * Validate cryptocurrency address
     */
    public function validateAddress(Request $request): JsonResponse
    {
        $request->validate([
            'address' => 'required|string',
            'currency' => 'required|string'
        ]);

        try {
            $blockchainServiceFactory = app(\App\Services\Blockchain\BlockchainServiceFactory::class);
            $service = $blockchainServiceFactory->create($request->currency);
            
            $isValid = $service->isValidAddress($request->address);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'address' => $request->address,
                    'currency' => $request->currency,
                    'is_valid' => $isValid
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported currency or validation error'
            ], 400);
        }
    }

    /**
     * Webhook endpoint for blockchain notifications
     */
    public function webhook(Request $request): JsonResponse
    {
        // This endpoint can be used by blockchain monitoring services
        // to notify about new transactions
        
        Log::info("Webhook received", $request->all());
        
        // Implement webhook logic based on your monitoring service
        // For example, if using BlockCypher, Alchemy, or custom monitoring
        
        return response()->json(['success' => true]);
    }

    /**
     * Get payment statistics
     */
    public function getStatistics(Request $request): JsonResponse
    {
        try {
            $from = $request->get('from', now()->subDays(30));
            $to = $request->get('to', now());
            
            $stats = \App\Models\PaymentTransaction::whereBetween('created_at', [$from, $to])
                ->selectRaw('
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = "confirmed" THEN 1 ELSE 0 END) as confirmed_transactions,
                    SUM(CASE WHEN status = "confirmed" THEN amount_usd ELSE 0 END) as total_amount_usd
                ')
                ->first();
            
            $currencyStats = \App\Models\PaymentTransaction::with('cryptocurrency')
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'confirmed')
                ->get()
                ->groupBy('cryptocurrency.symbol')
                ->map(function ($transactions) {
                    return [
                        'count' => $transactions->count(),
                        'total_amount' => $transactions->sum('amount'),
                        'total_amount_usd' => $transactions->sum('amount_usd')
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => $stats,
                    'by_currency' => $currencyStats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching statistics", ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics'
            ], 500);
        }
    }
}