# Crypto Payment Gateway - Laravel

A comprehensive cryptocurrency payment gateway built with Laravel, supporting BTC, LTC, ETH, XMR, SOL and ERC-20 tokens. Features **BIP39/BIP32/BIP44 HD wallets** with automatic address generation and real-time transaction monitoring using Trezor Blockbook APIs.

## Features

### 🔐 **HD Wallet System (BIP39/BIP32/BIP44)**
- **BIP39 Mnemonic Generation**: 24-word seed phrases for each user
- **BIP44 Derivation**: Multi-currency address generation (m/44'/coin_type'/0'/0/index)
- **Unlimited Addresses**: Users can generate as many addresses as needed
- **Secure Storage**: Encrypted mnemonics and private keys
- **Automatic Wallet Creation**: Wallets created automatically during user registration

### 🚀 **Multi-Currency Support**
- **Bitcoin (BTC)** - Using Trezor Blockbook API
- **Litecoin (LTC)** - Using Trezor Blockbook API  
- **Ethereum (ETH)** - Using Trezor Blockbook API
- **Monero (XMR)** - Using Trezor Blockbook API
- **Solana (SOL)** - Using native Solana RPC
- **ERC-20 Tokens** - USDT, USDC, LINK, UNI and more

### 📡 **Real-time Transaction Monitoring**
- **Automatic Detection**: Monitors all user addresses for incoming transactions
- **Webhook Notifications**: Instant notifications via user-configured webhooks
- **Confirmation Tracking**: Real-time confirmation count updates
- **Balance Updates**: Automatic balance synchronization

### 🔔 **Advanced Webhook System**
- **Event Types**: `payment_received`, `payment_confirmed`, `balance_update`, `address_generated`
- **Retry Logic**: Exponential backoff with configurable retry attempts
- **Signature Verification**: HMAC-SHA256 webhook signatures
- **Statistics**: Detailed webhook delivery statistics

## Requirements

- PHP 8.1+
- Laravel 10+
- MySQL/PostgreSQL
- Redis (recommended for caching)
- Composer

## Installation

### 1. Clone and Install Dependencies

```bash
git clone <repository-url>
cd crypto-payment-gateway
composer install
```

### 2. Environment Configuration

Copy the example environment file and configure your settings:

```bash
cp .env.example .env
```

Add the following configurations to your `.env` file:

```env
# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=crypto_gateway
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Crypto Gateway Configuration
CRYPTO_DEFAULT_EXPIRY_MINUTES=30
CRYPTO_CALLBACK_TIMEOUT=30
CRYPTO_MAX_CALLBACK_RETRIES=3

# Trezor Blockbook URLs (Optional - defaults provided)
BTC_BLOCKBOOK_URL=https://btc1.trezor.io
LTC_BLOCKBOOK_URL=https://ltc1.trezor.io
ETH_BLOCKBOOK_URL=https://eth1.trezor.io
XMR_BLOCKBOOK_URL=https://xmr1.trezor.io

# Testnet Configuration (set to true for testing)
BTC_TESTNET=false
LTC_TESTNET=false
ETH_TESTNET=false
XMR_TESTNET=false
SOL_TESTNET=false

# Solana RPC Configuration
SOL_RPC_URL=https://api.mainnet-beta.solana.com
SOL_TESTNET_RPC_URL=https://api.testnet.solana.com

# API Keys (Optional but recommended)
COINGECKO_API_KEY=your_coingecko_api_key
MORALIS_API_KEY=your_moralis_api_key
SOLSCAN_API_KEY=your_solscan_api_key

# Minimum Confirmations
BTC_MIN_CONFIRMATIONS=3
LTC_MIN_CONFIRMATIONS=6
ETH_MIN_CONFIRMATIONS=12
XMR_MIN_CONFIRMATIONS=10
SOL_MIN_CONFIRMATIONS=32

# Rate Limiting
BLOCKBOOK_TIMEOUT=30
BLOCKBOOK_RETRY_ATTEMPTS=3
BLOCKBOOK_RATE_LIMIT_DELAY=1
```

### 3. Database Setup

Run the migrations and seed the database:

```bash
php artisan key:generate
php artisan migrate
php artisan db:seed --class=CryptocurrencySeeder
```

### 4. Schedule Configuration

Add the following to your crontab for automated monitoring:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Then add this to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Monitor addresses every minute
    $schedule->command('crypto:monitor-addresses')->everyMinute();
    
    // Retry failed webhooks every 5 minutes
    $schedule->command('crypto:retry-webhooks')->everyFiveMinutes();
    
    // Update exchange rates every 10 minutes
    $schedule->command('crypto:update-rates')->everyTenMinutes();
}
```

## API Usage

### Base URL
```
https://yourdomain.com/api/v1
```

### Authentication

Register a new user and get API token:

```bash
POST /auth/register
Content-Type: application/json

{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "securepassword123",
    "password_confirmation": "securepassword123",
    "webhook_url": "https://yoursite.com/webhook",
    "webhook_events": ["payment_received", "payment_confirmed"]
}
```

Response:
```json
{
    "success": true,
    "data": {
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "api_key": "pk_abc123...",
            "webhook_url": "https://yoursite.com/webhook",
            "webhook_enabled": true
        },
        "wallet": {
            "id": 1,
            "created_at": "2025-09-10T12:00:00.000000Z"
        },
        "token": "bearer_token_here",
        "message": "User registered successfully with wallet and initial addresses"
    }
}
```

### Wallet Management

All wallet endpoints require authentication with Bearer token:

```bash
Authorization: Bearer your_token_here
```

#### Get Wallet Information

```bash
GET /crypto/wallet
```

Response:
```json
{
    "success": true,
    "data": {
        "wallet_id": 1,
        "created_at": "2025-09-10T12:00:00.000000Z",
        "balances": [
            {
                "symbol": "BTC",
                "name": "Bitcoin",
                "balance": 0.00150000,
                "address_count": 3
            },
            {
                "symbol": "ETH",
                "name": "Ethereum", 
                "balance": 0.25000000,
                "address_count": 2
            }
        ],
        "total_addresses": 8,
        "recent_transactions": [...]
    }
}
```

#### Generate New Address

```bash
POST /crypto/wallet/addresses
Content-Type: application/json

{
    "cryptocurrency": "BTC",
    "label": "My Bitcoin Address #2"
}
```

Response:
```json
{
    "success": true,
    "data": {
        "id": 15,
        "address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
        "cryptocurrency": "BTC",
        "derivation_path": "m/44'/0'/0'/0/1",
        "address_index": 1,
        "label": "My Bitcoin Address #2",
        "balance": 0,
        "created_at": "2025-09-10T13:00:00.000000Z"
    }
}
```

#### Get User Addresses

```bash
GET /crypto/wallet/addresses?cryptocurrency=BTC&page=1&per_page=25
```

#### Get User Transactions

```bash
GET /crypto/wallet/transactions?status=confirmed&page=1&per_page=25
```

#### Configure Webhook

```bash
POST /crypto/wallet/webhook/configure
Content-Type: application/json

{
    "webhook_url": "https://yoursite.com/crypto-webhook",
    "webhook_enabled": true,
    "webhook_events": [
        "payment_received",
        "payment_confirmed", 
        "balance_update",
        "address_generated"
    ]
}
```

### Webhook Notifications

When transactions are detected, the system sends webhook notifications:

#### Payment Received
```json
{
    "event": "payment_received",
    "timestamp": "2025-09-10T14:30:00.000000Z",
    "data": {
        "transaction_id": 123,
        "txid": "abc123...",
        "user_id": 1,
        "cryptocurrency": {
            "symbol": "BTC",
            "name": "Bitcoin",
            "is_token": false,
            "contract_address": null
        },
        "address": {
            "address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
            "label": "My Bitcoin Address #2",
            "derivation_path": "m/44'/0'/0'/0/1"
        },
        "amount": {
            "value": "0.00100000",
            "currency": "BTC",
            "usd_value": "45.50"
        },
        "transaction": {
            "from_address": "1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2",
            "confirmations": 1,
            "required_confirmations": 3,
            "status": "pending",
            "block_hash": "000000000019d6689c085ae165831e93...",
            "block_height": 750000,
            "block_time": "2025-09-10T14:25:00.000000Z",
            "fee":# Crypto Payment Gateway - Laravel

A comprehensive cryptocurrency payment gateway built with Laravel, supporting BTC, LTC, ETH, XMR, SOL and ERC-20 tokens. Uses Trezor Blockbook APIs for reliable blockchain interaction.

## Features

- **Multi-Currency Support**: BTC, LTC, ETH, XMR, SOL, and ERC-20 tokens
- **Trezor Blockbook Integration**: Reliable blockchain data using Trezor's public APIs
- **Real-time Monitoring**: Automatic payment confirmation tracking
- **QR Code Generation**: Easy payment interface for users
- **Callback System**: Webhook notifications when payments are confirmed
- **Exchange Rate Integration**: Live rates from CoinGecko
- **Admin Dashboard**: Manage cryptocurrencies, wallets, and transactions
- **Rate Limiting**: Built-in protection against API abuse
- **Comprehensive Logging**: Full audit trail of all operations

## Requirements

- PHP 8.1+
- Laravel 10+
- MySQL/PostgreSQL
- Redis (recommended for caching)
- Composer

## Installation

### 1. Clone and Install Dependencies

```bash
git clone <repository-url>
cd crypto-payment-gateway
composer install
```

### 2. Environment Configuration

Copy the example environment file and configure your settings:

```bash
cp .env.example .env
```

Add the following configurations to your `.env` file:

```env
# Database Configuration
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=crypto_gateway
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Crypto Gateway Configuration
CRYPTO_DEFAULT_EXPIRY_MINUTES=30
CRYPTO_CALLBACK_TIMEOUT=30
CRYPTO_MAX_CALLBACK_RETRIES=3

# Trezor Blockbook URLs (Optional - defaults provided)
BTC_BLOCKBOOK_URL=https://btc1.trezor.io
LTC_BLOCKBOOK_URL=https://ltc1.trezor.io
ETH_BLOCKBOOK_URL=https://eth1.trezor.io
XMR_BLOCKBOOK_URL=https://xmr1.trezor.io

# Testnet Configuration (set to true for testing)
BTC_TESTNET=false
LTC_TESTNET=false
ETH_TESTNET=false
XMR_TESTNET=false
SOL_TESTNET=false

# Solana RPC Configuration
SOL_RPC_URL=https://api.mainnet-beta.solana.com
SOL_TESTNET_RPC_URL=https://api.testnet.solana.com

# API Keys (Optional but recommended)
COINGECKO_API_KEY=your_coingecko_api_key
MORALIS_API_KEY=your_moralis_api_key
SOLSCAN_API_KEY=your_solscan_api_key

# Minimum Confirmations
BTC_MIN_CONFIRMATIONS=3
LTC_MIN_CONFIRMATIONS=6
ETH_MIN_CONFIRMATIONS=12
XMR_MIN_CONFIRMATIONS=10
SOL_MIN_CONFIRMATIONS=32

# Rate Limiting
BLOCKBOOK_TIMEOUT=30
BLOCKBOOK_RETRY_ATTEMPTS=3
BLOCKBOOK_RATE_LIMIT_DELAY=1
```

### 3. Database Setup

Run the migrations and seed the database:

```bash
php artisan key:generate
php artisan migrate
php artisan db:seed --class=CryptocurrencySeeder
```

### 4. Configure Wallet Addresses

Update the wallet addresses in `database/seeders/CryptocurrencySeeder.php` with your actual wallet addresses, then re-run the seeder:

```bash
php artisan db:seed --class=CryptocurrencySeeder --force
```

### 5. Schedule Configuration

Add the following to your crontab for automated payment monitoring:

```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Then add this to your `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    $schedule->command('crypto:monitor-payments')->everyMinute();
}
```

## API Usage

### Base URL
```
https://yourdomain.com/api/v1/crypto
```

### 1. Get Supported Currencies

```bash
GET /currencies
```

Response:
```json
{
    "success": true,
    "data": [
        {
            "symbol": "BTC",
            "name": "Bitcoin",
            "min_amount": "0.00001000",
            "max_amount": "100.00000000",
            "fee_percentage": "0.5000",
            "fixed_fee": "0.00000000",
            "is_token": false,
            "contract_address": null
        }
    ]
}
```

### 2. Create Payment

```bash
POST /payment
Content-Type: application/json

{
    "currency": "BTC",
    "amount": 0.001,
    "callback_url": "https://yoursite.com/webhook",
    "expires_in_minutes": 30,
    "metadata": {
        "order_id": "ORDER123",
        "customer_id": "CUST456"
    }
}
```

Response:
```json
{
    "success": true,
    "data": {
        "transaction_id": "TX_abc123def456",
        "amount": "0.00100000",
        "currency": "BTC",
        "to_address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
        "qr_data": "bitcoin:bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh?amount=0.001",
        "expires_at": "2025-09-10T15:30:00.000000Z",
        "status": "pending"
    }
}
```

### 3. Check Payment Status

```bash
GET /payment/{transaction_id}
```

Response:
```json
{
    "success": true,
    "data": {
        "transaction_id": "TX_abc123def456",
        "status": "confirmed",
        "amount": "0.00100000",
        "currency": "BTC",
        "to_address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
        "confirmations": 6,
        "required_confirmations": 3,
        "blockchain_tx_hash": "a1b2c3d4e5f6...",
        "expires_at": "2025-09-10T15:30:00.000000Z",
        "qr_data": "bitcoin:bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh?amount=0.001"
    }
}
```

### 4. Get Exchange Rates

```bash
GET /rates?symbols=BTC,ETH,LTC
```

Response:
```json
{
    "success": true,
    "data": {
        "BTC": 45000.50,
        "ETH": 3200.25,
        "LTC": 180.75
    }
}
```

### 5. Validate Address

```bash
POST /validate-address
Content-Type: application/json

{
    "address": "bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh",
    "currency": "BTC"
}
```

## Payment Status Lifecycle

1. **pending** - Payment created, waiting for blockchain transaction
2. **confirmed** - Transaction found and has required confirmations
3. **expired** - Payment expired before receiving transaction
4. **failed** - Transaction failed or was rejected

## Webhook Callbacks

When a payment status changes to "confirmed", the system will send a POST request to your callback URL:

```json
{
    "transaction_id": "TX_abc123def456",
    "status": "confirmed",
    "amount": "0.00100000",
    "currency": "BTC",
    "blockchain_tx_hash": "a1b2c3d4e5f6...",
    "confirmations": 6
}
```

## Monitoring Commands

### Manual Payment Check
```bash
php artisan crypto:monitor-payments
```

### With Options
```bash
php artisan crypto:monitor-payments --limit=100 --timeout=600
```

## Security Considerations

1. **API Rate Limiting**: Implement rate limiting on your API endpoints
2. **Webhook Verification**: Verify webhook authenticity in production
3. **Address Validation**: Always validate addresses before creating payments
4. **Private Key Security**: Store private keys securely if implementing sending
5. **HTTPS Only**: Use HTTPS for all API communications
6. **Database Security**: Encrypt sensitive data in database

## Testing

### Test with Testnet

Set testnet to true in your `.env`:
```env
BTC_TESTNET=true
ETH_TESTNET=true
# etc.
```

### Manual Testing

1. Create a payment using the API
2. Send a small amount to the generated address
3. Monitor the transaction status
4. Verify webhook callback (if configured)

## Troubleshooting

### Common Issues

1. **"No available wallet address"**
   - Ensure wallet addresses are seeded in database
   - Check that addresses are marked as active

2. **Exchange rate errors**
   - Verify CoinGecko API access
   - Check network connectivity

3. **Blockchain API timeouts**
   - Increase timeout values in config
   - Verify Blockbook API endpoints are accessible

4. **Payment not confirming**
   - Check transaction was sent to correct address
   - Verify minimum confirmation requirements
   - Check blockchain explorer manually

### Logs

Check Laravel logs for detailed error information:
```bash
tail -f storage/logs/laravel.log
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## License

This project is licensed under the MIT License.

## Support

For support and questions:
- Create an issue on GitHub
- Check the documentation
- Review the logs for error details

## Roadmap

- [ ] Additional blockchain support (BSC, Polygon, etc.)
- [ ] Built-in address generation
- [ ] Transaction sending capabilities
- [ ] Multi-signature wallet support
- [ ] Advanced analytics dashboard
- [ ] Mobile SDK