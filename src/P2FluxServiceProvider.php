<?php

declare(strict_types=1);

namespace P2Flux\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use P2Flux\P2FluxClient;

/**
 * Wires the P2Flux PHP SDK into a Laravel application.
 *
 * That is the whole job. This package adds no payment logic, no tables, no routes, no scheduler and
 * no second SDK surface - the client it binds is `P2Flux\P2FluxClient` from p2flux/sdk-php, and
 * every method on it is the SDK's own. Anything more would be a layer to keep in sync with a
 * protocol that already has one client per language.
 */
final class P2FluxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Defaults come from the package, so a fresh install works without publishing anything.
        $this->mergeConfigFrom(__DIR__ . '/../config/p2flux.php', 'p2flux');

        /* One client for the process. It holds no per-request state - just a base URL, a timeout and
         * an optional transport - so a singleton is both correct and one fewer object per request.
         * Bound by its own class name, so constructor injection of P2FluxClient simply works. */
        $this->app->singleton(P2FluxClient::class, static function (Application $app): P2FluxClient {
            $config = self::config($app);

            return new P2FluxClient([
                'apiUrl' => (string) $config['api_url'],
                'timeout' => (int) $config['timeout'],
            ]);
        });
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/p2flux.php' => $this->app->configPath('p2flux.php'),
        ], 'p2flux-config');

        $this->registerAboutCommand();
    }

    /**
     * Adds a P2Flux section to `php artisan about`.
     *
     * Only the two settings an operator needs to confirm, plus which SDK is installed. There is no
     * secret to leak here - P2Flux has no API key - and none is printed regardless.
     */
    private function registerAboutCommand(): void
    {
        if (!class_exists(AboutCommand::class)) {
            return; // A console-less application, or a framework build without the command.
        }

        $app = $this->app;

        AboutCommand::add('P2Flux', static function () use ($app): array {
            $config = self::config($app);

            return [
                'API URL' => (string) $config['api_url'],
                'Timeout' => $config['timeout'] . 's',
                'PHP SDK' => self::sdkVersion(),
            ];
        });
    }

    /**
     * The effective configuration, including on an application booted from a cached config.
     *
     * `mergeConfigFrom()` deliberately does nothing once `php artisan config:cache` has run, and
     * `config:cache` itself never registers providers - so a package's defaults are absent from the
     * cache file. An application that cached its config without publishing ours would otherwise
     * resolve a client with no API URL at all, which is exactly the kind of failure that only shows
     * up on deploy. Reading the package file as the fallback keeps `P2FLUX_API_URL` working there
     * too, because the file resolves `env()` when it is required.
     *
     * @return array{api_url: string, timeout: int|string}
     */
    private static function config(Application $app): array
    {
        /** @var Repository $repository */
        $repository = $app->make('config');
        /** @var array<string, mixed>|null $config */
        $config = $repository->get('p2flux');

        if (!is_array($config) || !isset($config['api_url'], $config['timeout'])) {
            /** @var array{api_url: string, timeout: int|string} $defaults */
            $defaults = require __DIR__ . '/../config/p2flux.php';
            $config = array_merge($defaults, is_array($config) ? $config : []);
        }

        /** @var array{api_url: string, timeout: int|string} $config */
        return $config;
    }

    private static function sdkVersion(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return InstalledVersions::getPrettyVersion('p2flux/sdk-php') ?? 'unknown';
        } catch (\OutOfBoundsException) {
            return 'unknown'; // Installed some other way; not worth an exception in `about`.
        }
    }
}
