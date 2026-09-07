# Payments

The flow, and which step may mark an order paid.

```
1. Laravel        createPayment()                  -> intent
2. your page      open <checkout>/#/pay/<intent>   -> the buyer's wallet pays
3. the checkout   postMessage to your page         -> a CLAIM: tx_hash, settlement_receipt
4. your page      POST the claim to Laravel        -> the browser's job ends here
5. Laravel        verifyPayment(intent, txHash)    -> the verdict
6. Laravel        mark the order paid              -> only on a valid verdict
```

**P2Flux sends no webhooks.** There is no server-to-server callback to wait for: the browser tells
you where to look, and your own verification decides what it means.

Runnable: [`examples/Http/Controllers/CreatePaymentController.php`](../examples/Http/Controllers/CreatePaymentController.php),
[`VerifyPaymentController.php`](../examples/Http/Controllers/VerifyPaymentController.php).

## Create

```php
$payment = $this->p2flux->createPayment([
    'recipient' => config('services.p2flux.recipient'),   // never from the request
    'amount' => $order->amount,                           // decimal string, "12.50"
]);

$order->update(['p2flux_intent' => $payment['intent']]);
```

Store the intent beside your own order id **before the buyer leaves the page**. Verification and
recovery both need it, and recovery still works long after the intent expires.

## Hand off

The intent rides in the URL fragment, which browsers never send to a server or put in `Referer`:

```php
$checkout = config('services.p2flux.checkout_url') . '/#/pay/' . rawurlencode($payment['intent']);
```

## Verify

```php
$verdict = $this->p2flux->verifyPayment($order->p2flux_intent, $txHash, $settlementReceipt);
```

| Verdict | Meaning |
|---|---|
| `$verdict['valid'] === true` | Settled. This, and only this, may mark the order paid. |
| `code: PAYMENT_CONFIRMING` | On chain, not deep enough yet. Poll the same hash. Never ask the buyer to pay again. |
| any other `code` | This transaction does not settle this intent. The order stays unpaid. |
| `P2FluxException` | Never reached a verdict. Unknown, not rejected: retry. |

A rejected payment is an answer with HTTP 200, not an exception. Only transport failures throw.

## Fulfil exactly once

Repeats are normal: a double-submitted page, a retried fetch, a cron sweep, a support button.

```php
DB::transaction(function () use ($order, $verdict): void {
    $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
    if ($fresh->status === 'paid') {
        return;                       // somebody already did this
    }
    $fresh->update(['status' => 'paid', 'tx_hash' => $verdict['tx_hash']]);
});
```

Re-check the status **inside** the lock. A unique constraint on the intent gives the same guarantee.

## When the claim never arrives

`recoverPayment($intent)` finds the settlement from the intent alone. Pure reads and idempotent, so
run it from a scheduled command over orders pending too long — see
[`examples/Console/RecoverPendingPayments.php`](../examples/Console/RecoverPendingPayments.php).
Never mint a second intent for the same order.

## Refunds

A refund is a plain USDC transfer from your own wallet back to the wallet that paid. P2Flux keeps no
refund history, so enforcing one refund per payment is your job, and the safe place is *before*
`prepareRefund()`. See [`examples/Http/Controllers/RefundController.php`](../examples/Http/Controllers/RefundController.php).

## Next

- [Paying the network fee in USDC](network-fee-in-usdc.md) · [Subscriptions](subscriptions.md)
- Protocol detail: [sdk-php payments](https://github.com/P2Flux/sdk-php/blob/main/docs/payments.md),
  [recovery](https://github.com/P2Flux/sdk-php/blob/main/docs/recovery.md),
  [errors](https://github.com/P2Flux/sdk-php/blob/main/docs/errors.md)
