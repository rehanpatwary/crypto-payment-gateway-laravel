<?php

namespace App\Services\Blockchain;

use App\Services\Blockchain\Contracts\BlockchainServiceInterface;
use InvalidArgumentException;

class BlockchainServiceFactory
{
    public function create(string $symbol): BlockchainServiceInterface
    {
        switch (strtoupper($symbol)) {
            case 'BTC':
                return new BitcoinService();
                
            case 'LTC':
                return new LitecoinService();
                
            case 'ETH':
                return new EthereumService();
                
            case 'SOL':
                return new SolanaService();
                
            case 'XMR':
                return new MoneroService();
                
            default:
                // Check if it's a token on Ethereum
                $cryptocurrency = \App\Models\Cryptocurrency::where('symbol', strtoupper($symbol))->first();
                if ($cryptocurrency && $cryptocurrency->is_token) {
                    return new EthereumTokenService($cryptocurrency);
                }
                
                throw new InvalidArgumentException("Unsupported cryptocurrency: {$symbol}");
        }
    }
}