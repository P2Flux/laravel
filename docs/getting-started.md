# Getting started

```bash
composer require p2flux/laravel
```

That is the whole installation. Laravel's package discovery registers the service provider, and the
defaults work immediately.

## What you get

| | |
|---|---|
| `P2Flux\P2FluxClient` in the container | Inject it anywhere. It is the official [PHP SDK](https://github.com/P2Flux/sdk-php)'s own client, not a wrapper. |
| `config/p2flux.php` | The SDK's two options, `api_url` and `timeout`. |
| `P2Flux\Laravel\Facades\P2Flux` | Optional, imported explicitly. No global alias. |
| A `php artisan about` section | API URL, timeout, installed SDK version. |

Nothing else. No tables, no models, no routes, no views, no commands, no scheduler.

## Configuration

```dotenv
P2FLUX_API_URL=https://api-test.p2flux.com   # default: https://api.p2flux.com
P2FLUX_TIMEOUT=60
```

Publishing is optional:

```bash
php artisan vendor:publish --tag=p2flux-config
```

`timeout` defaults to 60 seconds because a charge waits for on-chain confirmation. A timed-out
charge is safe: the next call answers `ALREADY_CHARGED`.

### There is no API key

P2Flux v1 has no API authentication. A payment is bound to an exact recipient and amount by the
customer's own signature, and the contract refuses a second charge in a period. What you configure
is which deployment to talk to.

| | API | Hosted checkout | Chain |
|---|---|---|---|
| Test | `https://api-test.p2flux.com` | `https://pay-test.p2flux.com` | Base Sepolia, faucet USDC |
| Production | `https://api.p2flux.com` | `https://pay.p2flux.com` | Base Mainnet, real USDC |

Every token is bound to the deployment that issued it, so store the environment with each order and
build the client for that stored environment when you verify, charge or refund it later.

### Your own values

Your payout wallet and checkout URL are application values, not SDK options, so they belong in your
config rather than the package's:

```php
// config/services.php
'p2flux' => [
    'recipient' => env('P2FLUX_RECIPIENT'),
    'checkout_url' => env('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com'),
],
```

## Injection

```php
use P2Flux\P2FluxClient;

final class CheckoutService
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }
}
```

The binding is a singleton: one client per process, holding a base URL and a timeout and no
per-request state.

## The facade

```php
use P2Flux\Laravel\Facades\P2Flux;

$caps = P2Flux::capabilities();
```

It resolves the same singleton. Constructor injection stays the recommendation, because a class that
receives its dependencies is a class you can hand a fake to.

## Config caching

`php artisan config:cache` is safe. Laravel skips a package's `mergeConfigFrom` once config is
cached, so this package reads its own defaults directly in that case — a cached application that
never published the config still gets the right API URL, and `P2FLUX_API_URL` still applies.

## Next

- [Payments](payments.md) · [Paying the network fee in USDC](network-fee-in-usdc.md)
- [Subscriptions](subscriptions.md) · [Testing](testing.md) · [Production checklist](production-checklist.md)
- Protocol detail: [sdk-php docs](https://github.com/P2Flux/sdk-php/tree/main/docs)
