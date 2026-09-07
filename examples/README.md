# Laravel examples

**These are merchant-application files, not part of `p2flux/laravel`.** Installing the package
registers a service provider, a config file and a facade — no routes, no controllers, no commands
and no scheduled jobs. Nothing in this directory is autoloaded or executed by the package.

Copy what you need into your own application and adapt it to your schema. These show safe patterns;
they are not a drop-in business schema.

| Example | Demonstrates | Docs |
|---|---|---|
| [`Http/Controllers/CreatePaymentController.php`](Http/Controllers/CreatePaymentController.php) | Mint an intent server-side, store it on the order, hand back a checkout URL | [Payments](../docs/payments.md) |
| [`Http/Controllers/CreateSponsoredPaymentController.php`](Http/Controllers/CreateSponsoredPaymentController.php) | Check `capabilities()` first, then create a payment a buyer can complete with USDC and no ETH | [Network fee in USDC](../docs/network-fee-in-usdc.md) |
| [`Http/Controllers/VerifyPaymentController.php`](Http/Controllers/VerifyPaymentController.php) | The trust boundary: verify the browser's claim, fulfil exactly once, fall back to recovery | [Payments](../docs/payments.md) |
| [`Http/Controllers/SubscriptionSignupController.php`](Http/Controllers/SubscriptionSignupController.php) | Terms → hosted checkout → prove the capability against your salt before storing it | [Subscriptions](../docs/subscriptions.md) |
| [`Http/Controllers/RestoreAllowanceController.php`](Http/Controllers/RestoreAllowanceController.php) | `INSUFFICIENT_ALLOWANCE` is repairable: hand the customer an approve session | [Subscriptions](../docs/subscriptions.md) |
| [`Http/Controllers/CancelSubscriptionController.php`](Http/Controllers/CancelSubscriptionController.php) | Stop collecting (yours) and offer on-chain revocation (the customer's wallet) | [Subscriptions](../docs/subscriptions.md) |
| [`Http/Controllers/RefundController.php`](Http/Controllers/RefundController.php) | Reserve before preparing, release on failure, verify from the original settlement | [Payments](../docs/payments.md) |
| [`Console/ChargeDueSubscriptions.php`](Console/ChargeDueSubscriptions.php) | Your renewal job: charge outcomes, `ALREADY_CHARGED`, per-subscription isolation | [Subscriptions](../docs/subscriptions.md) |
| [`Console/RecoverPendingPayments.php`](Console/RecoverPendingPayments.php) | Sweep for lost callbacks, and fill in evidence for periods already collected | [Payments](../docs/payments.md) |
| [`tests/PaymentTest.php`](tests/PaymentTest.php) | Testing your own code against a fake transport, with no crypto spent | [Testing](../docs/testing.md) |

Every one of these runs in this repository's own test suite against a canned P2Flux API — see
`tests/Examples/` — so the code here is executed, not just published.

## What your application provides

Configuration, in your own `config/services.php` (the package config carries only the SDK's two
options):

```php
'p2flux' => [
    'recipient' => env('P2FLUX_RECIPIENT'),                                  // your payout wallet
    'checkout_url' => env('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com'),
],
```

Two models, with columns the examples write. Names are yours to change; the shape is what matters.

**`orders`** — `status` (`pending`, `paid`, `refunded`), `amount`, `p2flux_intent`, `tx_hash`,
`settlement_receipt`, `refund_reserved_at`, `refund_tx_hash`.

**`subscriptions`** — `status` (`pending`, `active`, `needs_customer`, `stopped`, `cancelled`,
`needs_review`), `capability` (**encrypted**), `salt`, `p2flux_subscription_id`, `period_seconds`,
`period_index` (the period you expect to collect next), `paid_period_index`, `next_charge_at`,
`tx_hash`, `evidence_pending`, `last_status`.

The capability belongs in an `encrypted` cast. Whoever holds it can collect the customer's next
period, so it stays server-side, out of logs, and out of every response — no example returns it.

## The patterns these examples encode

1. **Payments are created server-side.** The recipient and amount come from config, never a request.
2. **A browser message is a claim.** Only `verifyPayment()` or `recoverPayment()` marks an order paid.
3. **Fulfilment happens once**, inside a transaction, with the status re-checked under a row lock.
4. **`ALREADY_CHARGED` is a success**, and only for the period your application expected. If the
   transaction hash cannot be recovered right away, the collection is still recorded and the
   evidence is marked pending for the sweep to fill in.
5. **A recovery failure is never a success**, and never aborts the rest of a renewal run.
6. **Refund reservations are released** when preparing fails, so an order cannot be stranded.
7. **Sponsorship is offered only after `capabilities()` says so.**
