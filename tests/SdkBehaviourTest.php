<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use P2Flux\Laravel\Facades\P2Flux;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * How the SDK behaves when reached through this package's binding.
 *
 * Not a re-test of the SDK: what is asserted here is that the wrapper changes none of the contracts
 * an integration depends on - a failure never reads as a payment, a rejection is an answer rather
 * than an exception, and a transport error stays retryable.
 */
final class SdkBehaviourTest extends TestCase
{
    /** @param array<string, array{0: int, 1: array<string, mixed>}> $responses */
    private function bind(array $responses = [], ?callable $transport = null): FakeTransport
    {
        $fake = new FakeTransport($responses);

        $this->app->instance(P2FluxClient::class, new P2FluxClient([
            'apiUrl' => 'https://api.example',
            'timeout' => 5,
            'transport' => $transport ?? $fake,
        ]));
        P2Flux::clearResolvedInstances();

        return $fake;
    }

    #[Test]
    public function the_singleton_survives_repeated_resolution(): void
    {
        $first = $this->app->make(P2FluxClient::class);

        for ($i = 0; $i < 100; $i++) {
            $this->assertSame($first, $this->app->make(P2FluxClient::class));
            $this->assertSame($first, P2Flux::getFacadeRoot());
        }
    }

    #[Test]
    public function an_unreachable_api_is_a_retryable_charge_result_not_a_decline(): void
    {
        /* The distinction that protects money: a transport failure says nothing about whether the
         * charge landed, so reporting it as a decline would let a merchant cancel a paying customer. */
        $this->bind(transport: static function (): array {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER', ['detail' => 'timeout']);
        });

        $result = $this->app->make(P2FluxClient::class)->charge('p2s2.k1.test.mac');

        $this->assertSame('NETWORK_ERROR', $result->status);
        $this->assertSame('RETRY_LATER', $result->action);
        $this->assertTrue($result->retryable);
        $this->assertFalse($result->ok);
    }

    #[Test]
    public function a_transport_failure_still_throws_for_the_non_charge_calls(): void
    {
        $this->bind(transport: static function (): array {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER');
        });

        $this->expectException(P2FluxException::class);

        $this->app->make(P2FluxClient::class)->status('p2s2.k1.test.mac');
    }

    #[Test]
    public function an_unexpected_transport_exception_is_not_swallowed(): void
    {
        // Anything that is not a P2FluxException must surface unchanged rather than being
        // reinterpreted as a payment outcome.
        $this->bind(transport: static function (): array {
            throw new RuntimeException('a bug in the host application transport');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a bug in the host application transport');

        $this->app->make(P2FluxClient::class)->status('p2s2.k1.test.mac');
    }

    #[Test]
    public function a_declined_charge_is_a_result_carrying_the_customer_action(): void
    {
        $this->bind(['/v1/charges' => [402, ['error' => 'INSUFFICIENT_BALANCE']]]);

        $result = $this->app->make(P2FluxClient::class)->charge('p2s2.k1.test.mac');

        $this->assertFalse($result->ok);
        $this->assertSame('CUSTOMER_ACTION_REQUIRED', $result->action);
    }

    #[Test]
    public function rate_limiting_throws_with_the_retry_after_intact(): void
    {
        $this->bind(['/v1/payments' => [429, ['error' => 'RATE_LIMITED', 'action' => 'RETRY_LATER', 'retry_after' => 120]]]);

        try {
            $this->app->make(P2FluxClient::class)->createPayment(['recipient' => '0x' . str_repeat('e', 40), 'amount' => '1.00']);
            $this->fail('RATE_LIMITED should throw for createPayment');
        } catch (P2FluxException $e) {
            $this->assertSame('RATE_LIMITED', $e->status);
            $this->assertSame('RETRY_LATER', $e->action);
            $this->assertSame(120, $e->raw['retry_after']);
        }
    }

    #[Test]
    public function a_confirming_settlement_is_never_valid(): void
    {
        $this->bind(['/v1/payments/verify' => [200, ['valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => '0xabc']]]);

        $verdict = $this->app->make(P2FluxClient::class)->verifyPayment('p2f1.x', '0xabc');

        $this->assertFalse($verdict['valid']);
        $this->assertSame('PAYMENT_CONFIRMING', $verdict['code']);
    }

    #[Test]
    public function a_transaction_that_settles_nothing_is_never_valid(): void
    {
        $this->bind(['/v1/payments/verify' => [200, ['valid' => false, 'code' => 'TRANSACTION_NOT_FOUND']]]);

        $verdict = $this->app->make(P2FluxClient::class)->verifyPayment('p2f1.x', '0xbad');

        $this->assertFalse($verdict['valid']);
    }

    #[Test]
    public function a_malformed_response_body_does_not_read_as_paid(): void
    {
        // An empty or nonsense body must fail the `=== true` test every example uses, not pass it.
        foreach ([[], ['unexpected' => 'shape'], ['valid' => 'yes'], ['valid' => 1]] as $body) {
            $this->bind(['/v1/payments/verify' => [200, $body]]);

            $verdict = $this->app->make(P2FluxClient::class)->verifyPayment('p2f1.x', '0xabc');

            $this->assertNotTrue($verdict['valid'] ?? false, 'a malformed body must not read as paid');
        }
    }

    #[Test]
    public function a_payment_that_has_not_settled_is_found_false_rather_than_an_error(): void
    {
        $this->bind(['/v1/payments/recover' => [404, ['found' => false, 'code' => 'PAYMENT_NOT_FOUND', 'as_of_block' => '123']]]);

        $found = $this->app->make(P2FluxClient::class)->recoverPayment('p2f1.x');

        $this->assertFalse($found['found']);
        $this->assertSame('PAYMENT_NOT_FOUND', $found['code']);
    }

    #[Test]
    public function an_unavailable_recovery_throws_rather_than_reading_as_not_found(): void
    {
        /* The difference that matters: "no settlement exists as of this block" is an answer, while
         * "the search could not be completed" is not, and treating the second as the first would
         * quietly abandon a paid order. */
        $this->bind(['/v1/payments/recover' => [503, ['error' => 'RECOVERY_UNAVAILABLE']]]);

        $this->expectException(P2FluxException::class);

        $this->app->make(P2FluxClient::class)->recoverPayment('p2f1.x');
    }

    #[Test]
    public function the_same_holds_for_recurring_recovery(): void
    {
        $this->bind(['/v1/charges/recover' => [404, ['found' => false, 'code' => 'PAYMENT_NOT_FOUND']]]);
        $found = $this->app->make(P2FluxClient::class)->recoverCharge('p2s2.x', 3);
        $this->assertFalse($found['found']);

        $this->bind(['/v1/charges/recover' => [503, ['error' => 'RECOVERY_UNAVAILABLE']]]);
        $this->expectException(P2FluxException::class);
        $this->app->make(P2FluxClient::class)->recoverCharge('p2s2.x', 3);
    }
}
