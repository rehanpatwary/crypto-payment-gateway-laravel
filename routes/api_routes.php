<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CryptoPaymentController;
use App\Http\Controllers\Api\WalletController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('api')->prefix('v1')->group(function () {
    
    // Public endpoints (no authentication required)
    Route::prefix('crypto')->group(function () {
        
        // Get supported cryptocurrencies
        Route::get('currencies', [CryptoPaymentController::class, 'getSupportedCurrencies']);
        
        // Get exchange rates
        Route::get('rates', [CryptoPaymentController::class, 'getExchangeRates']);
        
        // Validate address
        Route::post('validate-address', [CryptoPaymentController::class, 'validateAddress']);
        
        // Create payment (legacy - for backwards compatibility)
        Route::post('payment', [CryptoPaymentController::class, 'createPayment']);
        
        // Get payment status (legacy)
        Route::get('payment/{transactionId}', [CryptoPaymentController::class, 'getPaymentStatus']);
        
        // Webhook endpoint for blockchain notifications
        Route::post('webhook', [CryptoPaymentController::class, 'webhook']);
    });
    
    // Protected endpoints (require API key authentication via Sanctum)
    Route::middleware('auth:sanctum')->prefix('crypto')->group(function () {
        
        // Wallet Management
        Route::prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'getWallet']);
            Route::post('/', [WalletController::class, 'createWallet']);
            Route::post('refresh-balances', [WalletController::class, 'refreshBalances']);
            
            // Address Management
            Route::get('addresses', [WalletController::class, 'getAddresses']);
            Route::post('addresses', [WalletController::class, 'generateAddress']);
            Route::patch('addresses/{addressId}/label', [WalletController::class, 'updateAddressLabel']);
            
            // Transaction History
            Route::get('transactions', [WalletController::class, 'getTransactions']);
            Route::get('transactions/{transactionId}', [WalletController::class, 'getTransaction']);
            
            // Webhook Configuration
            Route::post('webhook/configure', [WalletController::class, 'configureWebhook']);
            Route::get('webhook/stats', [WalletController::class, 'getWebhookStats']);
        });
        
        // Get payment statistics
        Route::get('statistics', [CryptoPaymentController::class, 'getStatistics']);
        
        // Admin endpoints for managing cryptocurrencies and wallet addresses
        Route::prefix('admin')->group(function () {
            
            // Cryptocurrency management
            Route::apiResource('cryptocurrencies', \App\Http\Controllers\Api\Admin\CryptocurrencyController::class);
            
            // System wallet address management (for merchant payments)
            Route::apiResource('wallet-addresses', \App\Http\Controllers\Api\Admin\WalletAddressController::class);
            
            // Transaction management
            Route::get('transactions', [\App\Http\Controllers\Api\Admin\TransactionController::class, 'index']);
            Route::get('transactions/{transaction}', [\App\Http\Controllers\Api\Admin\TransactionController::class, 'show']);
            Route::patch('transactions/{transaction}/status', [\App\Http\Controllers\Api\Admin\TransactionController::class, 'updateStatus']);
            
            // Exchange rate management
            Route::post('rates/refresh', [\App\Http\Controllers\Api\Admin\ExchangeRateController::class, 'refresh']);
            Route::get('rates/history/{symbol}', [\App\Http\Controllers\Api\Admin\ExchangeRateController::class, 'history']);
            
            // User management
            Route::get('users', [\App\Http\Controllers\Api\Admin\UserController::class, 'index']);
            Route::get('users/{user}', [\App\Http\Controllers\Api\Admin\UserController::class, 'show']);
            Route::post('users/{user}/create-wallet', [\App\Http\Controllers\Api\Admin\UserController::class, 'createWallet']);
            Route::get('users/{user}/addresses', [\App\Http\Controllers\Api\Admin\UserController::class, 'getAddresses']);
            Route::get('users/{user}/transactions', [\App\Http\Controllers\Api\Admin\UserController::class, 'getTransactions']);
        });
    });
});

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('register', [\App\Http\Controllers\Auth\RegisterController::class, 'register']);
    Route::post('login', [\App\Http\Controllers\Auth\LoginController::class, 'login']);
    Route::middleware('auth:sanctum')->post('logout', [\App\Http\Controllers\Auth\LoginController::class, 'logout']);
    Route::middleware('auth:sanctum')->get('user', function (Request $request) {
        return $request->user();
    });
});

// Health check endpoint
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'version' => config('app.version', '1.0.0')
    ]);
});