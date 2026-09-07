# Changelog

## 0.1.1 - 2026-09-07

A pre-publication review of v0.1.0 before its first Packagist submission. Two medium findings, two
low, three informational; all fixed. Full write-up:
[`docs/pre-packagist-security-review-2026-09-07.md`](docs/pre-packagist-security-review-2026-09-07.md).

### Fixed

- **A mistyped `P2FLUX_TIMEOUT` silently removed every request timeout.** `(int) env(...)` turned
  `abc`, an empty value or `0` into `0`, which curl reads as "wait forever". The provider now
  refuses a non-positive or non-numeric timeout by name, at the moment the client is first resolved,
  and `artisan about` shows the offending value marked invalid rather than throwing.
- **An empty `P2FLUX_API_URL`** now fails with a message naming the environment variable and the
  config key, instead of the SDK's more general refusal. Whitespace is trimmed.
- **A failed refund prepare stranded the order.** The example reserved before preparing, which is
  right, but did not release the reservation when preparing threw - leaving the order permanently
  un-refundable after a transient error.
- **A recovery failure abandoned an entire renewal run.** `recoverCharge()` after `ALREADY_CHARGED`
  could throw and end the loop, skipping every remaining subscription. The collection is now
  recorded either way, with `evidence_pending` marking that the transaction hash is not known yet,
  and the sweep fills it in later. A charge result is acted on only when its subscription and period
  match what the application expected; a mismatch is flagged for review and writes nothing.

### Added

- **Two examples for workflows that had none**: `RestoreAllowanceController` (the approve session
  that repairs `INSUFFICIENT_ALLOWANCE`) and `CancelSubscriptionController` (stopping collection,
  and the browser-safe session for the customer's own on-chain revocation).
- **The examples are executed, not just published.** `tests/Examples/` runs every controller and
  command against a canned P2Flux API with in-memory application models: 32 scenarios covering
  valid, confirming, rejected, malformed, unreachable, rate-limited, already-charged, mismatched and
  recovery-failure paths, plus an assertion that no example response ever contains a capability.
- Boundary tests for every configuration value, and tests proving the package makes no network call
  during boot and registers no scheduled task.

### Changed

- Examples use plain Eloquent `create()`/`update()` with named columns instead of invented model
  methods, so they can be copied verbatim. `examples/README.md` lists the columns each one expects
  and states plainly that these are application files, not routes the package installs.

## 0.1.0 - 2026-09-07

First release.

### Added

- **`P2FluxServiceProvider`**, registered by Laravel's package discovery: binds the PHP SDK's
  `P2Flux\P2FluxClient` as a singleton, configured from `config/p2flux.php`. Constructor injection
  works immediately, and nothing needs registering by hand.
- **`config/p2flux.php`** with `api_url` and `timeout` — the SDK's own options, and only those.
  Defaults work without publishing; `php artisan vendor:publish --tag=p2flux-config` copies the file.
  There is no API key, because P2Flux v1 has no API authentication.
- **`P2Flux\Laravel\Facades\P2Flux`**, an optional facade over the same singleton, imported
  explicitly. No global alias is registered.
- **A P2Flux section in `php artisan about`**: API URL, timeout, and the installed SDK version.
- **Documentation and examples** for Laravel: payments, the USDC network fee, subscriptions with
  your own scheduler, testing with a fake transport, and a production checklist. Examples are
  application code, labelled as such, and ship with the package.

### Tested

Laravel 12 and 13 on PHP 8.2–8.4, against the lowest and the latest dependency sets.

Laravel 11 is deliberately not supported. Every 11.x release is covered by an unpatched security
advisory (PKSA-mdq4-51ck-6kdq affects `>=11.0.0,<12.0.0`), so Composer's default policy refuses to
install it and a package claiming that support would only be helping someone past the warning. The
12 and 13 floors are the first patches clear of the current advisories. The suite
runs under Orchestra Testbench with package discovery on, proves configuration reaches the SDK
rather than only Laravel, and repeats the checks against a cached config, which is how production
boots.
