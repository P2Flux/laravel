# Changelog

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

Laravel 11, 12 and 13 on PHP 8.2–8.4, against the lowest and the latest dependency sets. The suite
runs under Orchestra Testbench with package discovery on, proves configuration reaches the SDK
rather than only Laravel, and repeats the checks against a cached config, which is how production
boots.
