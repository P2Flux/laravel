# Pre-Packagist review — 2026-09-07

An independent bug, security and packaging review of `p2flux/laravel` before its first Packagist
submission. Scope is this package only: the P2Flux API, contracts and payment architecture were
reviewed elsewhere and are not revisited here.

| | |
|---|---|
| Reviewed artifact | tag `v0.1.0`, commit `8bc71cc` |
| Outcome | 2 medium, 2 low, 3 informational — all fixed |
| Released as | `v0.1.1` |
| Recommendation | **Publish v0.1.1.** `v0.1.0` is left in place, untouched, and superseded. |

## Findings

### F1 — MEDIUM · a mistyped timeout became "no timeout at all"

**Where** `config/p2flux.php`, `src/P2FluxServiceProvider.php`

**Issue** The config cast `(int) env('P2FLUX_TIMEOUT', 60)` turns `abc`, an empty value or `0` into
`0`, and passes a negative through unchanged. The SDK hands that straight to curl, where
`CURLOPT_TIMEOUT => 0` means *wait forever* and `CURLOPT_CONNECTTIMEOUT => min(10, 0)` falls back to
curl's own 300-second default.

**Impact** A single typo in `.env` silently removes every request timeout in production. A queue
worker charging subscriptions would hang on a stalled connection instead of failing and retrying,
and `artisan about` reported a confident `0s`. Configuration silently differed from what the
documentation promised.

**Reproducibility** Deterministic: `P2FLUX_TIMEOUT=abc`, resolve `P2FluxClient`, inspect its timeout.

**Fix** The provider validates with `filter_var(..., FILTER_VALIDATE_INT)` and requires `>= 1`,
throwing `InvalidArgumentException` naming `P2FLUX_TIMEOUT` and the offending value. Numeric strings
are accepted, because that is all `env()` ever returns. `artisan about` shows the raw value marked
`(invalid)` rather than throwing, since it is where an operator looks when something is wrong.

**Regression test** `tests/BoundaryTest.php` — ten refused values, four accepted, plus the `about`
case.

### F2 — LOW · an empty API URL failed without naming itself

**Where** `src/P2FluxServiceProvider.php`

**Issue** A blank `P2FLUX_API_URL` reached the SDK, which correctly refused it with `apiUrl is
required` — a message that names neither the environment variable nor the config key.

**Impact** Diagnosis time only; no unsafe behaviour.

**Fix** The provider throws first, naming `P2FLUX_API_URL`, `p2flux.api_url` and both valid values,
and trims surrounding whitespace. A malformed-but-present URL is deliberately left alone:
`P2FLUX_API_URL` is administrator-controlled, so pointing it at another host is a deployment
decision, not an injection. What matters is that an unreachable host stays a retryable transport
failure and never becomes a payment verdict, which is asserted.

**Regression test** `tests/BoundaryTest.php`.

### F3 — MEDIUM · a failed refund prepare stranded the order forever

**Where** `examples/Http/Controllers/RefundController.php`

**Issue** The example reserved `refund_reserved_at` before calling `prepareRefund()`, and did not
release it if the call threw. Reserving first is right — P2Flux keeps no refund history, so two
prepares are two valid refunds — but a transient 5xx then left the order permanently
un-refundable, with no signal that it had happened.

**Impact** Examples are copied into production. This one taught a pattern that silently strands
refunds, which surfaces as a support ticket long after the failure.

**Reproducibility** Deterministic: make prepare return 503, then try again.

**Fix** Release the reservation in the `catch` before returning. Nothing was prepared, so nothing
can be double-refunded by releasing it.

**Regression test** `tests/Examples/RefundExamplesTest.php` — failure releases, retry succeeds, and
a second concurrent attempt is still refused with 409 without reaching P2Flux.

### F4 — MEDIUM · a recovery failure abandoned an entire renewal run, and could lose a collection

**Where** `examples/Console/ChargeDueSubscriptions.php`

**Issue** After `ALREADY_CHARGED`, the example called `recoverCharge()` outside any `try`. That call
can legitimately fail (`RECOVERY_UNAVAILABLE`, transport), and the uncaught exception ended the loop
— every remaining subscription in the pass went uncharged. The period whose collection had just
been *proven* was also left unrecorded. Separately, the result's period was never checked against
the period the application believed it was collecting.

**Impact** One transient recovery failure could skip a billing run, and a mismatched period could
have been recorded against the wrong one.

**Fix** Three changes. Recovery is wrapped and allowed to fail; the collection is recorded either
way, with `evidence_pending` marking that the transaction hash is not known yet, and the recovery
sweep fills it in later. A result is only acted on when its `subscriptionId` and `periodIndex` match
what the application expected, otherwise the row is flagged `needs_review` and nothing is written.

**Regression test** `tests/Examples/SubscriptionExamplesTest.php` — recovery failure keeps the
collection and still charges the next subscription; a mismatched period changes nothing; the sweep
enriches pending evidence.

### F5 — INFORMATIONAL · dependency surface

`composer audit` is clean on every supported combination. No abandoned packages. Runtime
requirements are `p2flux/sdk-php`, `illuminate/support` and `illuminate/contracts`; the ~75
transitive packages come from `illuminate/support`, where `ServiceProvider` lives, and cannot be
avoided without vendoring framework internals. The Laravel 11 exclusion stands: every 11.x release
is covered by advisory `PKSA-mdq4-51ck-6kdq` with no patched version, and Composer's default policy
refuses to install it. That policy was not weakened anywhere.

### F6 — INFORMATIONAL · CI supply chain

`.github/workflows/tests.yml` requests `permissions: contents: read`, uses no secrets, and has no
publish step — releases cannot be mutated by it. It triggers on `pull_request`, not
`pull_request_target`, so a fork's code never runs with repository credentials. Matrix values are
literals in the workflow file and are interpolated only into `composer require` arguments, never
into a shell string built from untrusted input. Actions are pinned by major tag rather than commit
SHA; for a read-only, secret-less workflow this is an accepted trade, and it is recorded here rather
than changed.

### F7 — INFORMATIONAL · no side effects from installation

Installing the package registers a service provider and nothing else. Proven by test: zero routes,
zero migrations, zero views, zero package-owned commands, zero scheduled events, and no network call
during boot or `artisan about` — the client is constructed lazily inside the singleton closure, so a
P2Flux request happens only when application code asks for one.

## Example inventory

Every example is executed by the test suite against a canned API, not merely parsed.

| Example | Purpose | Runnable | Safe to copy | Tested |
|---|---|---|---|---|
| `CreatePaymentController` | Mint an intent, store it, return a checkout URL | yes | yes | 2 scenarios |
| `CreateSponsoredPaymentController` | Capability check, then a USDC-network-fee payment | yes | yes | 2 scenarios |
| `VerifyPaymentController` | Verify, fulfil once, recover when the hash is missing | yes | yes | 8 scenarios |
| `SubscriptionSignupController` | Terms, then prove the capability before storing it | yes | yes | 3 scenarios |
| `RestoreAllowanceController` | Approve session for `INSUFFICIENT_ALLOWANCE` (added in 0.1.1) | yes | yes | 1 scenario |
| `CancelSubscriptionController` | Stop collecting; offer on-chain revocation (added in 0.1.1) | yes | yes | 2 scenarios |
| `RefundController` | Reserve, prepare, release on failure, verify | yes | yes | 6 scenarios |
| `Console/ChargeDueSubscriptions` | The renewal job, every charge outcome | yes | yes | 7 scenarios |
| `Console/RecoverPendingPayments` | Lost-callback sweep and evidence enrichment | yes | yes | 2 scenarios |
| `tests/PaymentTest` | How to test an integration with a fake transport | reference | yes | linted, methods checked |

Rewritten in 0.1.1 so they can be copied verbatim: the examples now use plain Eloquent
`create()`/`update()` with named columns instead of invented model methods (`markPeriodPaid()`,
`stop()`), and `examples/README.md` lists the columns each expects.

## Review verdicts

| Area | Verdict |
|---|---|
| Service provider | Correct after F1/F2. Lazy singleton, no boot-time work, publishing guarded by `runningInConsole()`, no path assumptions beyond `__DIR__`. |
| Config cache | The known Laravel trap — `mergeConfigFrom` is skipped once config is cached, and `config:cache` never registers providers — is handled by falling back to the package's own file. Re-proven under `config:cache`, `optimize` and `about` on Laravel 12 and 13. |
| Facade | Resolves the container singleton, adds no behaviour, registers no global alias. Every `@method` matches a real SDK method and every public SDK method has one, asserted by reflection. |
| Package discovery | See F7. |
| Example safety | Server verification before fulfilment, browser messages treated as claims, repeat-safe writes inside a transaction with the status re-checked under a row lock, capability never in any response, no wallet or key handling, no broad `catch (Throwable)`. |
| Secret exposure | `about` prints only the API URL, the timeout and the SDK version — none of them secret. No example logs or returns a capability; asserted by a test that scans every example response. |
| Distribution | `src/`, `config/`, `docs/`, `examples/`, README, CHANGELOG, LICENSE, `composer.json`. Tests and CI are export-ignored. No `.env`, credentials, keys, vendor, lock file or caches. |

## What could not be proven here

`lockForUpdate()` taking a real lock needs a database and a second connection; the examples' harness
has neither, since the package must not grow a database to test its documentation. What is proven is
that the examples take the lock in the right place, re-check state inside the transaction, and write
exactly once under repeated calls. The locking itself is a property of Laravel and the database.

## Verification

152 tests, PHPStan level 8, `composer validate --strict`, `composer audit`, the ten-cell CI matrix
across Laravel 12 and 13 on PHP 8.2–8.4 at lowest and stable dependency sets, fresh
`composer create-project` applications for Laravel 12 and 13 installing the tag, and one live
read-only `capabilities()` call. No wallet, signature, private key or spend at any point.
