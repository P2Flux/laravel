<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use P2Flux\Laravel\P2FluxServiceProvider;
use P2Flux\P2FluxClient;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    /**
     * Testbench cannot discover the package under test: discovery reads the application's vendor
     * manifest, and here this package is the root rather than a dependency. So the provider is
     * named explicitly, exactly as Laravel's package-development documentation does it.
     *
     * Discovery itself is still proven, in the place where it is real: the clean Laravel
     * applications in .github/workflows and the release checks install this package with Composer
     * and assert the provider loads with nothing registered by hand.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [P2FluxServiceProvider::class];
    }

    /** Reads a private property off the SDK client, to prove what configuration actually reached it. */
    protected function clientProperty(P2FluxClient $client, string $name): mixed
    {
        $property = new ReflectionProperty(P2FluxClient::class, $name);
        $property->setAccessible(true);

        return $property->getValue($client);
    }

    /** Swaps in a client whose transport is a fake, keeping the container's configured URL/timeout. */
    protected function fakeClient(FakeTransport $transport): P2FluxClient
    {
        $configured = $this->app->make(P2FluxClient::class);

        return new P2FluxClient([
            'apiUrl' => $this->clientProperty($configured, 'apiUrl'),
            'timeout' => $this->clientProperty($configured, 'timeout'),
            'transport' => $transport,
        ]);
    }
}
