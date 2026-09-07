# P2Flux for Laravel

[![Packagist](https://img.shields.io/packagist/v/p2flux/laravel)](https://packagist.org/packages/p2flux/laravel)
[![PHP](https://img.shields.io/packagist/dependency-v/p2flux/laravel/php)](https://packagist.org/packages/p2flux/laravel)

```bash
composer require p2flux/laravel
```

The official Laravel integration for [P2Flux](https://p2flux.com): USDC payments and subscriptions
on Base that settle **straight to your own wallet**. No custody, no payout step, no account balance.

This package is deliberately thin. It binds the official
[PHP SDK](https://github.com/P2Flux/sdk-php)'s `P2Flux\P2FluxClient` into the container from your
config, adds a facade, a publishable config file and an `artisan about` section — and nothing else.
No tables, no models, no routes, no scheduler, no second SDK to keep in sync.

```
p2flux/laravel  ->  p2flux/sdk-php  ->  the P2Flux API
```

## Requirements

| | |
|---|---|
| PHP | 8.2+ (8.3+ on Laravel 13) |
| Laravel | 11, 12, 13 |
| Dependencies | `p2flux/sdk-php`, `illuminate/support`, `illuminate/contracts` |

## Nothing to register

Laravel's package discovery registers the service provider. There is no
`bootstrap/providers.php` edit, and the defaults work immediately — publishing the config file is
optional:

```bash
php artisan vendor:publish --tag=p2flux-config
```

Configure through the environment:

```dotenv
P2FLUX_API_URL=https://api-test.p2flux.com   # default: https://api.p2flux.com
P2FLUX_TIMEOUT=60
```

**There is no API key.** P2Flux v1 has no API authentication: a payment is bound to its recipient
and amount by the customer's own signature, and the contract refuses a second charge in a period.
Anything asking for a P2Flux key is describing a product that does not exist.

## Five-minute payment

Inject the SDK client anywhere Laravel resolves classes:

```php
use P2Flux\P2FluxClient;

final class CreatePaymentController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $order = Order::create(['user_id' => $request->user()->id, 'amount' => '12.50', 'status' => 'pending']);

        // The recipient and the amount come from your config and your records - never the request.
        $payment = $this->p2flux->createPayment([
            'recipient' => config('services.p2flux.recipient'),
            'amount' => $order->amount,
        ]);

        $order->update(['p2flux_intent' => $payment['intent']]);

        return response()->json([
            'order' => $order->id,
            'checkout' => 'https://pay.p2flux.com/#/pay/' . rawurlencode($payment['intent']),
        ]);
    }
}
```

## Hosted checkout

Send the buyer to the checkout URL. Their wallet pays, and the checkout reports back to the page
that opened it:

```js
const win = window.open(checkoutUrl, 'p2flux', 'width=460,height=680')

addEventListener('message', (event) => {
    if (event.origin !== new URL(CHECKOUT).origin) return

    if (event.data?.type === 'p2flux.ready') {
        win.postMessage({ type: 'p2flux.hello' }, new URL(CHECKOUT).origin)
    }

    if (event.data?.type === 'p2flux.payment.completed') {
        fetch('/payments/verify', {            // hand it to Laravel; decide nothing here
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order: ORDER_ID, tx_hash: event.data.tx_hash }),
        })
    }
})
```

## Verify before fulfilling

**P2Flux sends no webhooks.** The browser message says what a wallet did; your Laravel backend's
verdict is what decides.

```php
$verdict = $this->p2flux->verifyPayment($order->p2flux_intent, $txHash, $settlementReceipt);

if (($verdict['valid'] ?? false) !== true) {
    return ($verdict['code'] ?? '') === 'PAYMENT_CONFIRMING'
        ? response()->json(['status' => 'confirming'], 202)   // poll the same hash
        : response()->json(['status' => 'unsettled', 'code' => $verdict['code'] ?? null]);
}

// Fulfil exactly once: re-check the status inside the lock.
DB::transaction(function () use ($order, $verdict): void {
    $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
    if ($fresh->status === 'paid') {
        return;
    }
    $fresh->update(['status' => 'paid', 'tx_hash' => $verdict['tx_hash']]);
});
```

Lost the hash entirely? `recoverPayment($intent)` finds the settlement from the intent alone.
Full walk-through: [payments](docs/payments.md).

## Pay the network fee in USDC

A buyer holding USDC and **no ETH** can still pay. They sign a token authorization, the P2Flux
relayer submits the transaction and pays the Base network fee in ETH, and the buyer reimburses that
exact cost in USDC in the same transaction. Nothing is waived: the fee is quoted before they sign,
and they pay it in USDC rather than in ETH.

```php
$usdc = collect($this->p2flux->capabilities()['tokens'])->firstWhere('symbol', 'USDC');
$sponsored = $usdc && in_array('payment_token', $usdc['gas_payment_modes'], true);

$payment = $this->p2flux->createPayment([
    'recipient' => config('services.p2flux.recipient'),
    'amount' => '12.50',
    'gas_payment_mode' => $sponsored ? 'payment_token' : 'native',
]);
```

Ask `capabilities()` first: support is a fact about a deployment, not about a token.
Details: [paying the network fee in USDC](docs/network-fee-in-usdc.md).

## Subscriptions

P2Flux schedules nothing, and **this package starts no billing**. Your application decides a period
is due and calls `charge()` from your own command or job:

```php
$result = $p2flux->charge($subscription->capability);

if ($result->ok) {
    return;                             // CHARGED or ALREADY_CHARGED - the period is paid
}
match ($result->action) {
    'WAIT' => null,                                        // confirming; the money moved
    'RETRY_LATER' => $this->retryLater(),
    'CUSTOMER_ACTION_REQUIRED' => $subscription->needsCustomer($result->status),
    'STOP_SUBSCRIPTION' => $subscription->stop($result->status),
};
```

`charge()` never throws on a payment outcome. Schedule it yourself:

```php
Schedule::command('p2flux:charge-due')->hourly()->withoutOverlapping();
```

See [subscriptions](docs/subscriptions.md) and
[`examples/Console/ChargeDueSubscriptions.php`](examples/Console/ChargeDueSubscriptions.php).

## Facade

Optional, and imported explicitly — this package registers no global alias:

```php
use P2Flux\Laravel\Facades\P2Flux;

$caps = P2Flux::capabilities();
```

It resolves the same container singleton. Constructor injection stays the recommended interface,
because it is what makes your own classes testable.

## Testing

Your application never needs to spend crypto to be tested. The SDK takes a `transport` callable, so
a fake replaces HTTP entirely and the container carries it everywhere:

```php
$this->app->instance(P2FluxClient::class, new P2FluxClient([
    'apiUrl' => 'https://api.example',
    'transport' => fn (string $url, array $payload, int $timeout): array
        => [200, ['valid' => true, 'tx_hash' => '0xabc']],
]));
```

Recipes per outcome: [testing](docs/testing.md).

## Documentation

| | |
|---|---|
| [Getting started](docs/getting-started.md) | Install, configuration, injection, the facade |
| [Payments](docs/payments.md) | Create, hosted checkout, verify, recover |
| [Paying the network fee in USDC](docs/network-fee-in-usdc.md) | Buyers with no ETH |
| [Subscriptions](docs/subscriptions.md) | Signup, your renewal job, recovery |
| [Testing](docs/testing.md) | Fake transports, canned answers |
| [Production checklist](docs/production-checklist.md) | Before real money |
| [Examples](examples/) | Controllers, commands and a test, as application code |

Protocol detail lives in the PHP SDK's own documentation:
[sdk-php](https://github.com/P2Flux/sdk-php) · [p2flux.com/docs](https://p2flux.com/docs/)

## The other official packages

| | |
|---|---|
| PHP SDK | `composer require p2flux/sdk-php` — [Packagist](https://packagist.org/packages/p2flux/sdk-php) · [GitHub](https://github.com/P2Flux/sdk-php) |
| JavaScript / TypeScript SDK | `npm install @p2flux/sdk` — [npm](https://www.npmjs.com/package/@p2flux/sdk) · [GitHub](https://github.com/P2Flux/sdk-js) |

## License

MIT.
