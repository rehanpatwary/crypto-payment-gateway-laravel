<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;

Route::middleware('api')->prefix('v1')->group(function () {
    
    // Authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('register', [RegisterController::class, 'register']);
        Route::post('login', [LoginController::class, 'login']);
        Route::middleware('auth:sanctum')->post('logout', [LoginController::class, 'logout']);
        Route::middleware('auth:sanctum')->get('user', function (Request $request) {
            return $request->user();
        });
    });
    
    // Protected wallet endpoints
    Route::middleware('auth:sanctum')->prefix('crypto/wallet')->group(function () {
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
});

// Health check endpoint
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'version' => config('app.version', '1.0.0')
    ]);
});
