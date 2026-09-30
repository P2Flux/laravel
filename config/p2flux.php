<?php

declare(strict_types=1);

/**
 * P2Flux configuration.
 *
 * These are the P2Flux PHP SDK's own options, and nothing else. There is no API key: P2Flux v1 has
 * no API authentication, because a payment is bound to its recipient and amount by the customer's
 * own signature and the contract refuses a second charge in a period. Anything asking you to paste
 * a P2Flux key is describing a product that does not exist.
 *
 * Your payout wallet and your checkout URL are application values, not SDK options, so they belong
 * in your own config rather than here.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | API URL
    |--------------------------------------------------------------------------
    |
    | Production is https://api.p2flux.com (Base Mainnet, real money). The test
    | environment is https://api-test.p2flux.com (Base Sepolia, faucet USDC).
    |
    | Tokens are bound to the deployment that issued them, so store the
    | environment with every order and use the stored one for later calls.
    |
    */

    'api_url' => env('P2FLUX_API_URL', 'https://api.p2flux.com'),

    /*
    |--------------------------------------------------------------------------
    | Request timeout
    |--------------------------------------------------------------------------
    |
    | Seconds, as a positive integer. The SDK's own default is 60, because a
    | charge waits for on-chain confirmation, which on a busy public RPC can
    | take tens of seconds. Abandoning early is safe but noisy: the payment may
    | still land, and the next call answers ALREADY_CHARGED.
    |
    | A blank, non-numeric or non-positive value is refused when the client is
    | first resolved, rather than becoming "no timeout at all".
    |
    */

    'timeout' => env('P2FLUX_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | x402 paywall (the `p2flux.paywall` middleware)
    |--------------------------------------------------------------------------
    |
    | Charge AI agents for a route, in USDC. `recipient` is your wallet on Base:
    | every payment goes to it. `price` is per request (at least 0.01); a route
    | can set its own: ->middleware('p2flux.paywall:0.20').
    |
    | `prepaid` also offers agents a prepaid balance (no transaction per request;
    | paid out to you at 2 USDC or weekly, less 3%). Pay-per-request keeps 1%,
    | at least 0.003 USDC.
    |
    | `on_unavailable`: when P2Flux cannot be reached, 'refuse' answers 503 and
    | 'free' serves the route without payment.
    |
    */

    'paywall' => [
        'recipient' => env('P2FLUX_RECIPIENT'),
        'price' => env('P2FLUX_PAYWALL_PRICE', '0.05'),
        'prepaid' => env('P2FLUX_PAYWALL_PREPAID', true),
        'on_unavailable' => env('P2FLUX_PAYWALL_ON_UNAVAILABLE', 'refuse'),
    ],

];
