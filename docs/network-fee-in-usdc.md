# Paying the network fee in USDC

A buyer holding USDC and **no ETH** can still pay. They sign a token authorization instead of
sending a transaction; the P2Flux relayer submits it and pays the Base network fee in ETH, and the
buyer reimburses that exact cost in USDC inside the same transaction.

**Nothing is waived.** The network fee is real, it is quoted before the buyer signs, and the buyer
pays it — in USDC rather than in ETH. USDC is never converted.

Runnable: [`examples/Http/Controllers/CreateSponsoredPaymentController.php`](../examples/Http/Controllers/CreateSponsoredPaymentController.php).

## Check the capability first

Architectural possibility is not support. A token that implements the right standards on a network
P2Flux has not deployed to reports `false`, and the request is refused with
`PAYMENT_TOKEN_GAS_UNSUPPORTED` before a buyer sees anything.

```php
use Illuminate\Support\Facades\Cache;

$sponsored = Cache::remember('p2flux.sponsors_usdc', now()->addHour(), function (): bool {
    $usdc = collect($this->p2flux->capabilities()['tokens'])->firstWhere('symbol', 'USDC');

    return $usdc !== null
        && in_array('payment_token', $usdc['gas_payment_modes'], true)
        && ($usdc['operations']['one_time_payment'] ?? false) === true;
});
```

Capabilities change only when the deployment does, so cache them rather than asking per checkout.

## Create the payment

```php
$payment = $this->p2flux->createPayment([
    'recipient' => config('services.p2flux.recipient'),
    'amount' => '12.50',
    'gas_payment_mode' => $sponsored ? 'payment_token' : 'native',
]);
```

Everything else is unchanged: the same intent, the same checkout URL, the same server-side
verification. Without the field nothing changes at all — the buyer sends the transaction and pays
the network fee in ETH, exactly as before.

## Read the accounting

```php
$verdict = $this->p2flux->verifyPayment($order->p2flux_intent, $txHash);

if (($verdict['valid'] ?? false) && isset($verdict['accounting'])) {
    $a = $verdict['accounting'];          // USDC base units: 1 USDC = 1000000
    $a['buyer_total_units'];              // price + the quoted network fee, and nothing else
    $a['merchant_net_units'];             // price - 1% - the fixed 0.10 network fee
    $a['network_fee_units'];              // quoted before signing; exactly what was charged
}
```

Who funds what does not change: the 1% and the fixed 0.10 USDC network fee come out of the amount,
exactly as a subscription collection works.

## Per-wallet limits

A buyer wallet may ask P2Flux to send at most **10 sponsored transactions in any rolling hour and 20
in any rolling day**, across every merchant and operation. Over that the API answers `RATE_LIMITED`
with `retry_after` and nothing is spent. Your `charge()` calls are never counted against it.

## Next

- [Payments](payments.md) · [Subscriptions](subscriptions.md)
- Protocol detail and contract addresses:
  [sdk-php network fee in USDC](https://github.com/P2Flux/sdk-php/blob/main/docs/network-fee-in-usdc.md)
