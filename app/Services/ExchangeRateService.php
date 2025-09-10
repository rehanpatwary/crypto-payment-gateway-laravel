<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ExchangeRateService
{
    protected $cacheTime = 300; // 5 minutes
    protected $apiUrl = 'https://api.coingecko.com/api/v3';

    /**
     * Get exchange rate for a single currency
     */
    public function getRate(string $symbol): float
    {
        $rates = $this->getRates([$symbol]);
        return $rates[strtoupper($symbol)] ?? 0;
    }

    /**
     * Get exchange rates for multiple currencies
     */
    public function getRates(array $symbols = null): array
    {
        if (!$symbols) {
            $symbols = ['BTC', 'ETH', 'LTC', 'XMR', 'SOL'];
        }

        $symbols = array_map('strtoupper', $symbols);
        $rates = [];

        foreach ($symbols as $symbol) {
            $cacheKey = "exchange_rate_{$symbol}";
            
            $rate = Cache::remember($cacheKey, $this->cacheTime, function () use ($symbol) {
                return $this->fetchRateFromAPI($symbol);
            });

            if ($rate > 0) {
                $rates[$symbol] = $rate;
                $this->updateDatabaseRate($symbol, $rate);
            } else {
                // Fallback to database if API fails
                $dbRate = $this->getRateFromDatabase($symbol);
                if ($dbRate > 0) {
                    $rates[$symbol] = $dbRate;
                }
            }
        }

        return $rates;
    }

    /**
     * Fetch rate from external API
     */
    protected function fetchRateFromAPI(string $symbol): float
    {
        try {
            $coinId = $this->getCoinGeckoId($symbol);
            if (!$coinId) {
                return 0;
            }

            $response = Http::timeout(10)->get("{$this->apiUrl}/simple/price", [
                'ids' => $coinId,
                'vs_currencies' => 'usd'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data[$coinId]['usd'] ?? 0;
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch exchange rate from API", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
        }

        return 0;
    }

    /**
     * Get rate from database
     */
    protected function getRateFromDatabase(string $symbol): float
    {
        $exchangeRate = ExchangeRate::where('symbol', $symbol)->first();
        return $exchangeRate ? (float)$exchangeRate->rate_usd : 0;
    }

    /**
     * Update rate in database
     */
    protected function updateDatabaseRate(string $symbol, float $rate): void
    {
        try {
            ExchangeRate::updateOrCreate(
                ['symbol' => $symbol],
                [
                    'rate_usd' => $rate,
                    'updated_at' => now()
                ]
            );
        } catch (\Exception $e) {
            Log::error("Failed to update exchange rate in database", [
                'symbol' => $symbol,
                'rate' => $rate,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get CoinGecko ID for symbol
     */
    protected function getCoinGeckoId(string $symbol): ?string
    {
        $mapping = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'LTC' => 'litecoin',
            'XMR' => 'monero',
            'SOL' => 'solana',
            'USDT' => 'tether',
            'USDC' => 'usd-coin',
            'DAI' => 'dai',
            'LINK' => 'chainlink',
            'UNI' => 'uniswap'
        ];

        return $mapping[strtoupper($symbol)] ?? null;
    }

    /**
     * Convert amount from one currency to another
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            return $amount;
        }

        $fromRate = $this->getRate($fromCurrency);
        $toRate = $this->getRate($toCurrency);

        if ($fromRate <= 0 || $toRate <= 0) {
            throw new \RuntimeException("Cannot convert {$fromCurrency} to {$toCurrency}: missing exchange rates");
        }

        // Convert to USD first, then to target currency
        $usdAmount = $amount * $fromRate;
        return $usdAmount / $toRate;
    }

    /**
     * Get historical rates (if needed for analytics)
     */
    public function getHistoricalRates(string $symbol, int $days = 30): array
    {
        try {
            $coinId = $this->getCoinGeckoId($symbol);
            if (!$coinId) {
                return [];
            }

            $response = Http::timeout(30)->get("{$this->apiUrl}/coins/{$coinId}/market_chart", [
                'vs_currency' => 'usd',
                'days' => $days,
                'interval' => $days > 90 ? 'daily' : 'hourly'
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['prices'] ?? [];
            }
        } catch (\Exception $e) {
            Log::error("Failed to fetch historical rates", [
                'symbol' => $symbol,
                'days' => $days,
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Refresh all rates
     */
    public function refreshAllRates(): array
    {
        $symbols = ['BTC', 'ETH', 'LTC', 'XMR', 'SOL'];
        
        // Clear cache for all symbols
        foreach ($symbols as $symbol) {
            Cache::forget("exchange_rate_{$symbol}");
        }

        return $this->getRates($symbols);
    }
}