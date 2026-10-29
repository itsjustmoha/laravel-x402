<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Receiving wallet
    |--------------------------------------------------------------------------
    |
    | The PUBLIC address (0x...) that receives the payments. Payments settle
    | directly from the payer to this address through the facilitator; this
    | package never holds funds. Never put a private key or seed phrase here or
    | anywhere else in your application.
    |
    */

    'pay_to' => env('X402_PAY_TO'),

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    |
    | CAIP-2 id of the default network. Testnet first:
    |   eip155:84532  Base Sepolia (testnet, default)
    |   eip155:8453   Base mainnet (real money, needs a production facilitator)
    |
    */

    'network' => env('X402_NETWORK', 'eip155:84532'),

    /*
    | Upper bound (seconds) the client may take to complete the payment. Sent
    | to clients as maxTimeoutSeconds.
    */

    'max_timeout_seconds' => (int) env('X402_MAX_TIMEOUT_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Facilitator
    |--------------------------------------------------------------------------
    |
    | The service that verifies and settles payments on chain. The default
    | https://x402.org/facilitator is the public TESTNET facilitator documented
    | by the x402 project; it refuses mainnet traffic, and this package refuses
    | to combine it with a mainnet network.
    |
    | "auth" may name a class implementing
    | Itsjustmoha\X402\Core\Facilitator\AuthHeadersProvider (resolved from the
    | container) for facilitators that require API credentials, such as
    | Coinbase CDP (https://api.cdp.coinbase.com/platform/v2/x402).
    |
    */

    'facilitator' => [
        'url' => env('X402_FACILITATOR_URL', 'https://x402.org/facilitator'),
        'timeout' => (float) env('X402_FACILITATOR_TIMEOUT', 30),
        'auth' => env('X402_FACILITATOR_AUTH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    |
    | USDC is built in for eip155:84532, 421614, 8453, 1, 137, 42161 and 43114
    | (source: coinbase/x402 typescript/packages/mechanisms/evm/src/defaultAssets.ts).
    | Add or override networks here. "name"/"version" are the token's EIP-712
    | domain; the token must support EIP-3009 transferWithAuthorization.
    |
    | 'eip155:43113' => [
    |     'address' => '0x...', 'decimals' => 6, 'name' => 'USD Coin',
    |     'version' => '2', 'symbol' => 'USDC', 'testnet' => true,
    | ],
    |
    */

    'assets' => [],

    /*
    |--------------------------------------------------------------------------
    | Service metadata (Bazaar discovery)
    |--------------------------------------------------------------------------
    |
    | Optional fields shown by discovery catalogs. Invalid values are dropped
    | (name: printable ASCII <= 32 chars; up to 5 tags; public http(s) icon URL).
    |
    */

    'service' => [
        'name' => env('X402_SERVICE_NAME'),
        'tags' => [],
        'icon_url' => env('X402_SERVICE_ICON_URL'),
    ],

    /*
    | Also accept legacy x402 v1 clients (X-PAYMENT header, v1 network names).
    | When enabled, 402 bodies use the v1 format; v2 clients keep reading the
    | PAYMENT-REQUIRED header.
    */

    'v1_compatibility' => (bool) env('X402_V1_COMPATIBILITY', false),

    /*
    | Cache store used for the per-payment lock that stops one signed payment
    | from being used by concurrent requests. Use a store shared by all your
    | servers (redis, database, memcached, dynamodb). null = default store.
    */

    'lock_store' => env('X402_LOCK_STORE'),

    /*
    | Payment outcomes are logged without payer addresses or headers.
    */

    'logging' => [
        'enabled' => (bool) env('X402_LOGGING', true),
        'channel' => env('X402_LOG_CHANNEL'),
    ],

];
