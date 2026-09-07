<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use P2Flux\Laravel\Facades\P2Flux;
use P2Flux\P2FluxClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * Production runs on a cached config, and that is where a package's config usually breaks.
 *
 * `config:cache` serialises the merged array to a file and, from then on, the framework never calls
 * a provider's `mergeConfigFrom` again. A package that reads config at the wrong moment - or puts a
 * closure in it, which cannot be serialised - works perfectly in development and fails on deploy.
 * These tests run the real command and then assert against the cached state.
 */
final class ConfigCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Artisan::call('config:clear');

        parent::tearDown();
    }

    #[Test]
    public function the_cache_omits_package_config_and_the_package_copes(): void
    {
        /* Documenting the pitfall this whole file exists for. `config:cache` bootstraps a fresh
         * application with only LoadConfiguration - providers never register - so a package's
         * merged defaults are absent from the cache file, and at runtime `mergeConfigFrom` skips
         * itself once config is cached. A package that trusted config() here would resolve a client
         * with no API URL on every deployed application that had not published the config. */
        $this->assertSame(0, Artisan::call('config:cache'));

        $cached = require $this->app->getCachedConfigPath();
        $this->assertArrayNotHasKey('p2flux', $cached, 'Laravel behaviour changed - revisit the provider');

        $this->reloadFromCachedConfig();

        $client = $this->app->make(P2FluxClient::class);
        $this->assertSame('https://api.p2flux.com', $this->clientProperty($client, 'apiUrl'));
        $this->assertSame(60, $this->clientProperty($client, 'timeout'));
    }

    #[Test]
    public function the_client_still_resolves_and_is_still_a_singleton_after_caching(): void
    {
        Artisan::call('config:cache');
        $this->reloadFromCachedConfig();

        $client = $this->app->make(P2FluxClient::class);

        $this->assertInstanceOf(P2FluxClient::class, $client);
        $this->assertSame($client, $this->app->make(P2FluxClient::class));
    }

    #[Test]
    public function cached_settings_reach_the_sdk(): void
    {
        config(['p2flux.api_url' => 'https://api-test.p2flux.com', 'p2flux.timeout' => 21]);
        Artisan::call('config:cache');
        $this->reloadFromCachedConfig();

        $client = $this->app->make(P2FluxClient::class);
        $this->assertSame('https://api-test.p2flux.com', $this->clientProperty($client, 'apiUrl'));
        $this->assertSame(21, $this->clientProperty($client, 'timeout'));

        $transport = new FakeTransport();
        $this->fakeClient($transport)->capabilities();

        $this->assertSame('https://api-test.p2flux.com/v1/capabilities', $transport->calls[0]['url']);
        $this->assertSame(21, $transport->calls[0]['timeout']);
    }

    #[Test]
    public function the_facade_and_a_fake_transport_still_work_after_caching(): void
    {
        Artisan::call('config:cache');
        $this->reloadFromCachedConfig();

        $transport = new FakeTransport(['/v1/capabilities' => [200, ['chain_id' => 8453, 'tokens' => []]]]);
        $this->app->instance(P2FluxClient::class, $this->fakeClient($transport));
        P2Flux::clearResolvedInstances();

        $this->assertSame($this->app->make(P2FluxClient::class), P2Flux::getFacadeRoot());
        $this->assertSame(8453, P2Flux::capabilities()['chain_id']);
    }

    #[Test]
    public function artisan_about_works_against_a_cached_config(): void
    {
        Artisan::call('config:cache');
        $this->reloadFromCachedConfig();

        Artisan::call('about', ['--only' => 'p2flux']);

        $this->assertStringContainsString('https://api.p2flux.com', Artisan::output());
    }

    /** Replaces the live config with what `config:cache` wrote, which is what a deployed app boots with. */
    private function reloadFromCachedConfig(): void
    {
        /** @var array<string, mixed> $cached */
        $cached = require $this->app->getCachedConfigPath();

        $this->app['config']->set($cached);
        $this->app->forgetInstance(P2FluxClient::class);

        /* The container's bindings are re-registered because a cached-config boot skips the
         * provider's mergeConfigFrom but still runs register(); doing it explicitly here keeps the
         * facade pointing at this application rather than a stale one. */
        (new \P2Flux\Laravel\P2FluxServiceProvider($this->app))->register();

        P2Flux::clearResolvedInstances();
        P2Flux::setFacadeApplication($this->app);
    }
}
