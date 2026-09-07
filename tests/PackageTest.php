<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use P2Flux\Laravel\Facades\P2Flux;
use P2Flux\Laravel\P2FluxServiceProvider;
use P2Flux\P2FluxClient;
use PHPUnit\Framework\Attributes\Test;

final class PackageTest extends TestCase
{
    #[Test]
    public function the_provider_is_loaded(): void
    {
        $this->assertArrayHasKey(P2FluxServiceProvider::class, $this->app->getLoadedProviders());
    }

    #[Test]
    public function composer_declares_the_provider_for_package_discovery(): void
    {
        /* This is what `composer require` reads to register the provider without anyone editing
         * bootstrap/providers.php. A typo here is invisible in this suite - Testbench names the
         * provider itself - and breaks every real installation, so it is asserted directly. */
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);

        $this->assertSame(
            [P2FluxServiceProvider::class],
            $composer['extra']['laravel']['providers'] ?? null,
        );

        // And no global alias: the facade is an explicit import, never a name injected into every file.
        $this->assertArrayNotHasKey('aliases', $composer['extra']['laravel']);
    }

    #[Test]
    public function config_defaults_work_without_publishing_anything(): void
    {
        $this->assertSame('https://api.p2flux.com', config('p2flux.api_url'));
        $this->assertSame(60, config('p2flux.timeout'));
    }

    #[Test]
    public function the_client_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(P2FluxClient::class, $this->app->make(P2FluxClient::class));
    }

    #[Test]
    public function the_client_is_a_singleton(): void
    {
        $this->assertSame($this->app->make(P2FluxClient::class), $this->app->make(P2FluxClient::class));
    }

    #[Test]
    public function constructor_injection_gives_the_same_client(): void
    {
        $consumer = $this->app->make(ExampleConsumer::class);

        $this->assertSame($this->app->make(P2FluxClient::class), $consumer->p2flux);
    }

    #[Test]
    public function the_facade_resolves_the_container_singleton(): void
    {
        $this->assertSame($this->app->make(P2FluxClient::class), P2Flux::getFacadeRoot());
    }

    #[Test]
    public function the_facade_forwards_calls_to_the_sdk(): void
    {
        $transport = new FakeTransport(['/v1/capabilities' => [200, ['chain_id' => 8453, 'tokens' => []]]]);
        $this->app->instance(P2FluxClient::class, $this->fakeClient($transport));
        P2Flux::clearResolvedInstances();

        $this->assertSame(8453, P2Flux::capabilities()['chain_id']);
        $this->assertStringEndsWith('/v1/capabilities', $transport->calls[0]['url']);
    }

    #[Test]
    public function configuration_reaches_the_sdk_not_just_laravel(): void
    {
        config(['p2flux.api_url' => 'https://api-test.p2flux.com', 'p2flux.timeout' => 12]);
        $this->app->forgetInstance(P2FluxClient::class);

        $transport = new FakeTransport();
        $client = $this->fakeClient($transport);
        $client->capabilities();

        $this->assertSame('https://api-test.p2flux.com/v1/capabilities', $transport->calls[0]['url']);
        $this->assertSame(12, $transport->calls[0]['timeout']);
    }

    #[Test]
    public function a_trailing_slash_in_the_configured_url_does_not_double_up(): void
    {
        config(['p2flux.api_url' => 'https://api.p2flux.com/']);
        $this->app->forgetInstance(P2FluxClient::class);

        $transport = new FakeTransport();
        $this->fakeClient($transport)->capabilities();

        $this->assertSame('https://api.p2flux.com/v1/capabilities', $transport->calls[0]['url']);
    }

    #[Test]
    public function the_config_can_be_published(): void
    {
        $published = $this->app->configPath('p2flux.php');
        @unlink($published);

        Artisan::call('vendor:publish', ['--tag' => 'p2flux-config']);

        $this->assertFileExists($published);
        $this->assertSame(
            require __DIR__ . '/../config/p2flux.php',
            require $published,
            'the published file must be the package default, not a rewritten copy',
        );

        @unlink($published);
    }

    #[Test]
    public function there_is_no_api_key_anywhere_in_the_configuration(): void
    {
        // P2Flux v1 has no API authentication. A key here would be an invented concept.
        $config = config('p2flux');
        $this->assertSame(['api_url', 'timeout'], array_keys($config));

        $source = (string) file_get_contents(__DIR__ . '/../config/p2flux.php');
        foreach (['P2FLUX_API_KEY', 'api_key', 'secret', 'private_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, str_replace('no API key', '', $source));
        }
    }

    #[Test]
    public function the_package_adds_no_routes_migrations_views_or_commands(): void
    {
        /* Not "the application has no routes" - a Testbench skeleton has its own, and so does a
         * real application. What must be true is that this package contributed none. */
        $ours = array_filter(
            $this->app['router']->getRoutes()->getRoutes(),
            static function ($route): bool {
                $action = $route->getAction();
                $target = strtolower($route->uri() . ' ' . ($route->getName() ?? '') . ' ' . (is_string($action['uses'] ?? null) ? $action['uses'] : ''));

                return str_contains($target, 'p2flux');
            },
        );
        $this->assertSame([], array_values($ours), 'the package must add no routes');

        $migrations = array_filter(
            $this->app->make('migrator')->paths(),
            static fn (string $path): bool => str_contains($path, 'p2flux'),
        );
        $this->assertSame([], $migrations);

        $hints = array_filter(
            array_keys($this->app['view']->getFinder()->getHints()),
            static fn (string $namespace): bool => str_contains($namespace, 'p2flux'),
        );
        $this->assertSame([], $hints);

        $commands = array_filter(
            array_keys(Artisan::all()),
            static fn (string $name): bool => str_starts_with($name, 'p2flux'),
        );
        $this->assertSame([], $commands, 'billing must never start because a package was installed');
    }

    #[Test]
    public function artisan_about_reports_the_settings_and_no_secrets(): void
    {
        Artisan::call('about', ['--only' => 'p2flux']);
        $output = Artisan::output();

        $this->assertStringContainsString('P2Flux', $output);
        $this->assertStringContainsString('https://api.p2flux.com', $output);
        foreach (['API_KEY', 'secret', 'private'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $output);
        }
    }
}

/** Stands in for a merchant application's own class. */
final class ExampleConsumer
{
    public function __construct(public readonly P2FluxClient $p2flux)
    {
    }
}
