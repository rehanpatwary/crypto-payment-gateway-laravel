<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Register a new user and create their wallet
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
                'webhook_url' => 'nullable|url|max:500',
                'webhook_events' => 'nullable|array',
                'webhook_events.*' => 'string|in:payment_received,payment_confirmed,balance_update,address_generated'
            ]);

            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'webhook_url' => $request->webhook_url,
                'webhook_enabled' => !empty($request->webhook_url),
                'webhook_events' => $request->webhook_events ?? ['payment_received', 'payment_confirmed']
            ]);

            // Create wallet for user
            $wallet = $this->walletService->createWalletForUser($user);

            // Generate API token
            $token = $user->createToken('api-token')->plainTextToken;

            Log::info("User registered with wallet", [
                'user_id' => $user->id,
                'email' => $user->email,
                'wallet_id' => $wallet->id
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'api_key' => $user->api_key,
                        'webhook_url' => $user->webhook_url,
                        'webhook_enabled' => $user->webhook_enabled,
                        'created_at' => $user->created_at->toISOString()
                    ],
                    'wallet' => [
                        'id' => $wallet->id,
                        'created_at' => $wallet->created_at->toISOString()
                    ],
                    'token' => $token,
                    'message' => 'User registered successfully with wallet and initial addresses'
                ]
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("User registration failed", [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please try again.'
            ], 500);
        }
    }
}

class LoginController extends Controller
{
    /**
     * Login user and return API token
     */
    public function login(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string'
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            // Revoke existing tokens (optional - comment out for multiple sessions)
            $user->tokens()->delete();

            // Create new token
            $token = $user->createToken('api-token')->plainTextToken;

            Log::info("User logged in", [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'api_key' => $user->api_key,
                        'webhook_url' => $user->webhook_url,
                        'webhook_enabled' => $user->webhook_enabled,
                        'has_wallet' => !is_null($user->userWallet)
                    ],
                    'token' => $token
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error("Login failed", [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Login failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Revoke the current token
            $request->user()->currentAccessToken()->delete();

            Log::info("User logged out", [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);

        } catch (\Exception $e) {
            Log::error("Logout failed", [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Logout failed'
            ], 500);
        }
    }
}