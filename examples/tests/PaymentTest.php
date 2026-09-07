<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace Tests\Feature;

use App\Models\Order;
use P2Flux\P2FluxClient;
use Tests\TestCase;

/**
 * Testing your own P2Flux code without spending anything.
 *
 * The SDK takes a `transport` callable, so a test replaces the HTTP layer and the container binding
 * carries it everywhere your application already injects the client. Nothing here reaches a network.
 */
final class PaymentTest extends TestCase
{
    /** @param array<string, array{0: int, 1: array<string, mixed>}> $responses keyed by path suffix */
    private function fakeP2Flux(array $responses): object
    {
        $recorder = new class($responses) {
            /** @var list<array{url: string, payload: array<string, mixed>}> */
            public array $calls = [];

            /** @param array<string, array{0: int, 1: array<string, mixed>}> $responses */
            public function __construct(private array $responses)
            {
            }

            /** @param array<string, mixed> $payload */
            public function __invoke(string $url, array $payload, int $timeout): array
            {
                $this->calls[] = ['url' => $url, 'payload' => $payload];

                foreach ($this->responses as $suffix => $response) {
                    if (str_ends_with($url, $suffix)) {
                        return $response;
                    }
                }

                return [404, ['error' => 'INVALID_REQUEST', 'action' => 'INVALID_REQUEST']];
            }
        };

        $this->app->instance(P2FluxClient::class, new P2FluxClient([
            'apiUrl' => 'https://api.example',
            'transport' => $recorder,
        ]));

        return $recorder;
    }

    public function test_an_order_is_not_paid_while_the_settlement_is_confirming(): void
    {
        $this->fakeP2Flux([
            '/v1/payments/verify' => [200, ['valid' => false, 'code' => 'PAYMENT_CONFIRMING']],
        ]);
        $order = Order::factory()->create(['status' => 'pending', 'p2flux_intent' => 'p2f1.k1.test.mac']);

        $this->actingAs($order->user)
            ->postJson('/payments/verify', ['order' => $order->id, 'tx_hash' => '0xabc'])
            ->assertStatus(202);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_a_valid_verdict_pays_the_order_exactly_once(): void
    {
        $this->fakeP2Flux([
            '/v1/payments/verify' => [200, ['valid' => true, 'tx_hash' => '0xabc', 'block_number' => 1]],
        ]);
        $order = Order::factory()->create(['status' => 'pending', 'p2flux_intent' => 'p2f1.k1.test.mac']);

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($order->user)
                ->postJson('/payments/verify', ['order' => $order->id, 'tx_hash' => '0xabc'])
                ->assertOk()
                ->assertJson(['status' => 'paid']);
        }

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $order->fulfilments()->count());
    }

    public function test_the_recipient_comes_from_configuration_not_the_request(): void
    {
        $recorder = $this->fakeP2Flux([
            '/v1/payments' => [200, ['intent' => 'p2f1.k1.test.mac', 'reference' => '0x', 'amount' => '12.500000']],
        ]);
        config(['services.p2flux.recipient' => '0x' . str_repeat('e', 40)]);

        $this->actingAs(Order::factory()->make()->user)
            ->postJson('/payments', ['recipient' => '0xattacker'])
            ->assertOk();

        $this->assertSame('0x' . str_repeat('e', 40), $recorder->calls[0]['payload']['recipient']);
    }
}
