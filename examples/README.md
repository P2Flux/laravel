# Laravel examples

**This is merchant application code, not part of the package.** Copy what you need into your own
app and adapt it. Nothing here is autoloaded, registered or scheduled by `p2flux/laravel`.

Every file assumes your application has its own `Order` or `Subscription` model with its own
migration. The package deliberately ships neither: your orders belong in your schema, and the
unpaid → paid transition belongs in your transaction.

| File | |
|---|---|
| `Http/Controllers/CreatePaymentController.php` | Mint an intent server-side, hand back a checkout URL |
| `Http/Controllers/VerifyPaymentController.php` | The trust boundary: verify, then fulfil exactly once |
| `Http/Controllers/CreateSponsoredPaymentController.php` | A buyer paying with USDC and no ETH |
| `Http/Controllers/SubscriptionSignupController.php` | Terms → checkout → prove the capability |
| `Http/Controllers/RefundController.php` | Prepare, send from your wallet, verify |
| `Console/ChargeDueSubscriptions.php` | Your renewal job, scheduled by your app |
| `Console/RecoverPendingPayments.php` | A recovery sweep for lost callbacks |
| `tests/PaymentTest.php` | Testing your own code with a fake transport |

Configuration these examples expect in your own `config/services.php` (the package config carries
only the SDK's own options):

```php
'p2flux' => [
    'recipient' => env('P2FLUX_RECIPIENT'),                                  // your payout wallet
    'checkout_url' => env('P2FLUX_CHECKOUT_URL', 'https://pay.p2flux.com'),
],
```
