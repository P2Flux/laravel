# Testing your application

Everything here runs offline. No wallet, no USDC, no chain, no API.

The SDK takes a `transport` callable, so a test replaces the HTTP layer and the container binding
carries it everywhere your code already injects the client. That is the whole mechanism — there is
nothing in this package to mock.

Runnable: [`examples/tests/PaymentTest.php`](../examples/tests/PaymentTest.php).

## Swap the binding

```php
use P2Flux\P2FluxClient;

$this->app->instance(P2FluxClient::class, new P2FluxClient([
    'apiUrl' => 'https://api.example',
    'transport' => fn (string $url, array $payload, int $timeout): array
        => [200, ['valid' => true, 'tx_hash' => '0xabc']],
]));
```

Every constructor-injected class now receives it. If you use the facade, add
`P2Flux::clearResolvedInstances();` so it resolves the new instance.

## A recording fake

Assert on what was sent, which is usually the point:

```php
final class FakeTransport
{
    /** @var list<array{url: string, payload: array}> */
    public array $calls = [];

    public function __construct(private array $responses = [])
    {
    }

    public function __invoke(string $url, array $payload, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload];

        foreach ($this->responses as $suffix => $response) {
            if (str_ends_with($url, $suffix)) {
                return $response;
            }
        }

        return [404, ['error' => 'INVALID_REQUEST', 'action' => 'INVALID_REQUEST']];
    }
}
```

```php
$transport = new FakeTransport([
    '/v1/payments' => [200, ['intent' => 'p2f1.k1.test.mac', 'reference' => '0xref', 'amount' => '12.500000']],
]);
$this->app->instance(P2FluxClient::class, new P2FluxClient(['apiUrl' => 'https://api.example', 'transport' => $transport]));

$this->postJson('/payments', ['recipient' => '0xattacker'])->assertOk();

// The recipient must come from your configuration, never from the request body.
$this->assertSame(config('services.p2flux.recipient'), $transport->calls[0]['payload']['recipient']);
```

## Canned answers per outcome

The branches integrations get wrong:

```php
// still confirming: your code must poll, never fulfil, never re-ask the buyer
'/v1/payments/verify' => [200, ['valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => '0xabc']],

// this transaction settles nothing: the order stays unpaid
'/v1/payments/verify' => [200, ['valid' => false, 'code' => 'TRANSACTION_NOT_FOUND']],

// the buyer's wallet hit the sponsored-transaction limit; nothing was spent
'/v1/payments' => [429, ['error' => 'RATE_LIMITED', 'action' => 'RETRY_LATER', 'retry_after' => 120]],

// sponsorship is not offered here at all: fall back to native, do not retry
'/v1/payments' => [400, ['error' => 'PAYMENT_TOKEN_GAS_UNSUPPORTED', 'action' => 'INVALID_REQUEST']],

// charge outcomes
'/v1/charges' => [200, ['status' => 'CHARGED', 'tx_hash' => '0xdef', 'period_index' => 3]],
'/v1/charges' => [200, ['status' => 'ALREADY_CHARGED', 'period_index' => 3]],
'/v1/charges' => [402, ['error' => 'INSUFFICIENT_BALANCE']],

// a settlement recovered from the chain
'/v1/charges/recover' => [200, ['found' => true, 'tx_hash' => '0xdef', 'period_index' => 3]],
```

An unreachable API — the case where the answer is "unknown", never "declined":

```php
'transport' => function (): array {
    throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => 'timeout']);
},
```

`charge()` turns that into a `ChargeResult` with `NETWORK_ERROR` / `RETRY_LATER` rather than
throwing, so your renewal job must treat it as unknown and never cancel a subscription on it.

## The three tests worth writing

1. Your verify endpoint called twice with the same payload fulfils **once**.
2. Your renewal job run twice in the same period charges **once** (`ALREADY_CHARGED` is a success).
3. The recipient comes from configuration, not from the request.

## Next

- [Production checklist](production-checklist.md) · [Payments](payments.md)
- Protocol detail: [sdk-php testing](https://github.com/P2Flux/sdk-php/blob/main/docs/testing.md)
