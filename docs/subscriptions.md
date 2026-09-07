# Subscriptions

P2Flux schedules nothing, and **this package starts no billing**. Installing a Composer package must
never begin charging customers, so there is no hidden scheduler here: your application decides a
period is due and calls `charge()`.

Runnable: [`examples/Http/Controllers/SubscriptionSignupController.php`](../examples/Http/Controllers/SubscriptionSignupController.php),
[`examples/Console/ChargeDueSubscriptions.php`](../examples/Console/ChargeDueSubscriptions.php).

## Signup

```php
$setup = $this->p2flux->createSubscription([
    'recipient' => config('services.p2flux.recipient'),
    'amount' => '5.00',
    'period' => 30 * 86400,          // SECONDS
]);

$subscription->update(['salt' => $setup['salt']]);
$checkout = config('services.p2flux.checkout_url') . '/#/subscribe/' . rawurlencode($setup['setup_token']);
```

The customer approves USDC once and signs one EIP-712 authorization. The checkout posts the
capability back to your page.

## Prove the capability before storing it

A cryptographically valid capability can still be the **wrong** one:

```php
$state = $this->p2flux->status($capability);

if ($state['terms']['salt'] !== $subscription->salt
    || strtolower($state['terms']['recipient']) !== strtolower(config('services.p2flux.recipient'))) {
    throw new RuntimeException('SETUP_MISMATCH');
}
```

The `p2s2` capability is a bearer credential: whoever holds it can collect the customer's next
period. Store it encrypted (an `encrypted` cast does this), server-side only, never in a URL or a
log, and never in a browser.

## Your renewal job

```php
$result = $p2flux->charge($subscription->capability);

if ($result->ok && $result->txHash !== null) {
    $subscription->markPeriodPaid($result->periodIndex, $result->txHash);      // CHARGED
} elseif ($result->ok) {
    // ALREADY_CHARGED: collected, and names no transaction. Recover the settlement if you need
    // to attribute, audit or refund the period.
    $found = $p2flux->recoverCharge($subscription->capability, (int) $result->periodIndex);
    $subscription->markPeriodPaid($result->periodIndex, $found['tx_hash'] ?? null);
} elseif ($result->status === 'CONFIRMING') {
    // On chain, not settled. Keep the period open and ask again. Never send a second charge.
} else {
    match ($result->action) {
        'RETRY_LATER' => null,
        'CUSTOMER_ACTION_REQUIRED' => $subscription->needsCustomer($result->status),
        'STOP_SUBSCRIPTION' => $subscription->stop($result->status),
        default => report(new RuntimeException("needs a human: {$result->status}")),
    };
}
```

`charge()` never throws on a payment outcome, so there is nothing to catch. Classify on `->action`,
not on `->status`, and a code this client has never seen still lands in the right branch.

## Schedule it yourself

```php
// routes/console.php
Schedule::command('p2flux:charge-due')->hourly()->withoutOverlapping();
Schedule::command('p2flux:recover-pending')->everyFifteenMinutes();
```

The contract allows one charge per billing period, so a retry after a timeout or a crashed worker
answers `ALREADY_CHARGED` rather than charging twice.

## Cancellation

Runnable: [`examples/Http/Controllers/CancelSubscriptionController.php`](../examples/Http/Controllers/CancelSubscriptionController.php),
[`RestoreAllowanceController.php`](../examples/Http/Controllers/RestoreAllowanceController.php).

Stopping collection is yours: stop calling `charge()`. Revoking the on-chain authorization is the
customer's own transaction — `createCancellationSession()` returns a browser-safe token for the
hosted cancel page. Never hand a browser the capability itself.

## Next

- [Payments](payments.md) · [Testing](testing.md)
- Protocol detail: [sdk-php subscriptions](https://github.com/P2Flux/sdk-php/blob/main/docs/subscriptions.md)
