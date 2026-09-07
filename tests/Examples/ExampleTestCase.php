<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests\Examples;

use App\Models\Order;
use App\Models\Record;
use App\Models\Subscription;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use P2Flux\Laravel\Tests\FakeTransport;
use P2Flux\Laravel\Tests\TestCase;
use P2Flux\P2FluxClient;

/**
 * Runs the published examples as code.
 *
 * The merchant half is faked - in-memory models, a transaction helper that simply runs the closure -
 * because the package must not grow a database to test documentation. The P2Flux half is real: the
 * examples resolve P2FluxClient through this package's own container binding, and only the HTTP
 * transport is canned.
 *
 * What that cannot prove: that `lockForUpdate()` actually locks. That needs a real database and a
 * second connection, and it is a property of Laravel and the database rather than of these
 * examples. What is proven here is that the examples take the lock in the right place, re-check
 * state inside it, and never write twice.
 */
abstract class ExampleTestCase extends TestCase
{
    protected FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        Order::reset();
        Subscription::reset();

        config([
            'services.p2flux.recipient' => '0x' . str_repeat('e', 40),
            'services.p2flux.checkout_url' => 'https://pay-test.p2flux.com',
        ]);

        /* A transaction helper that runs the closure, and nothing more. It implements the resolver
         * contract because Laravel's validator asks the `db` binding for one at boot. */
        DB::swap(new class implements ConnectionResolverInterface {
            public function transaction(callable $callback, int $attempts = 1): mixed
            {
                return $callback();
            }

            public function connection($name = null): ConnectionInterface
            {
                throw new \RuntimeException('the example harness has no database connection');
            }

            public function getDefaultConnection(): string
            {
                return 'none';
            }

            public function setDefaultConnection($name): void
            {
            }
        });
    }

    /** @param array<string, array{0: int, 1: array<string, mixed>}> $responses keyed by path suffix */
    protected function p2flux(array $responses, ?callable $transport = null): void
    {
        $this->transport = new FakeTransport($responses);

        $this->app->instance(P2FluxClient::class, new P2FluxClient([
            'apiUrl' => 'https://api.example',
            'timeout' => 5,
            'transport' => $transport ?? $this->transport,
        ]));
    }

    /** Invokes a single-action controller, or a named method, the way the router would. */
    protected function call_controller(string $controller, array $input = [], string $method = '__invoke'): JsonResponse
    {
        $request = Request::create('/', 'POST', $input);
        $request->setUserResolver(static fn (): Authenticatable => new class implements Authenticatable {
            public int $id = 7;

            public function getAuthIdentifierName(): string { return 'id'; }
            public function getAuthIdentifier(): mixed { return $this->id; }
            public function getAuthPassword(): string { return ''; }
            public function getAuthPasswordName(): string { return 'password'; }
            public function getRememberToken(): string { return ''; }
            public function setRememberToken($value): void {}
            public function getRememberTokenName(): string { return ''; }
        });

        /** @var JsonResponse $response */
        $response = $this->app->make($controller)->{$method}($request);

        return $response;
    }

    /**
     * The decoded body. Named `body` rather than `json` because Testbench already has a `json()`.
     *
     * @return array<string, mixed>
     */
    protected function body(JsonResponse $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true);

        return $decoded;
    }

    /** Runs an example console command with the container's client, as Artisan would. */
    protected function runCommand(string $command): int
    {
        /** @var \Illuminate\Console\Command $instance */
        $instance = $this->app->make($command);
        $instance->setLaravel($this->app);

        return $instance->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        );
    }

    /** @return list<string> the API paths the examples actually called, in order */
    protected function calledPaths(): array
    {
        return array_map(
            static fn (array $call): string => (string) parse_url($call['url'], PHP_URL_PATH),
            $this->transport->calls,
        );
    }

    /** Asserts no response ever hands a charge capability to a browser. */
    protected function assertNoCapabilityLeaked(JsonResponse $response): void
    {
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('p2s2.', $body, 'a capability must never reach a browser');
        $this->assertStringNotContainsString('capability', $body);
    }

    protected function order(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'user_id' => 7,
            'amount' => '12.50',
            'status' => 'pending',
            'p2flux_intent' => 'p2f1.k1.stub.mac',
        ]);
    }

    protected function subscription(array $attributes = []): Subscription
    {
        return Subscription::create($attributes + [
            'user_id' => 7,
            'status' => 'active',
            'capability' => 'p2s2.k1.stub.mac',
            'p2flux_subscription_id' => '0x' . str_repeat('9', 64),
            'period_seconds' => 2592000,
            'period_index' => 3,
            'next_charge_at' => now()->subMinute(),
            'evidence_pending' => false,
        ]);
    }
}
