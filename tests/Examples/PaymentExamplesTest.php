<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests\Examples;

use App\Http\Controllers\CreatePaymentController;
use App\Http\Controllers\CreateSponsoredPaymentController;
use App\Http\Controllers\VerifyPaymentController;
use App\Models\Order;
use P2Flux\P2FluxException;
use PHPUnit\Framework\Attributes\Test;

final class PaymentExamplesTest extends ExampleTestCase
{
    private const INTENT = [200, ['intent' => 'p2f1.k1.stub.mac', 'reference' => '0xref', 'amount' => '12.500000']];

    private const CAPABILITIES = [200, ['chain_id' => 8453, 'tokens' => [[
        'symbol' => 'USDC',
        'gas_payment_modes' => ['native', 'payment_token'],
        'operations' => ['one_time_payment' => true],
    ]]]];

    #[Test]
    public function creating_a_payment_stores_the_intent_and_never_trusts_the_request(): void
    {
        $this->p2flux(['/v1/payments' => self::INTENT]);

        $response = $this->call_controller(CreatePaymentController::class, ['recipient' => '0xattacker', 'amount' => '0.01']);

        $this->assertSame(200, $response->getStatusCode());
        $sent = $this->transport->calls[0]['payload'];
        $this->assertSame(config('services.p2flux.recipient'), $sent['recipient'], 'the recipient must come from configuration');
        $this->assertSame('12.50', $sent['amount'], 'the amount must come from the order, not the request');

        $order = Order::all()[0];
        $this->assertSame('p2f1.k1.stub.mac', $order->p2flux_intent);
        $this->assertSame('pending', $order->status, 'creating a payment must not mark anything paid');
        $this->assertStringContainsString('#/pay/', $this->body($response)['checkout']);
    }

    #[Test]
    public function a_failure_to_create_is_reported_and_leaves_the_order_unpaid(): void
    {
        $this->p2flux(['/v1/payments' => [429, ['error' => 'RATE_LIMITED', 'action' => 'RETRY_LATER']]]);

        $response = $this->call_controller(CreatePaymentController::class);

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame('pending', Order::all()[0]->status);
    }

    #[Test]
    public function a_sponsored_payment_asks_capabilities_before_offering_it(): void
    {
        $this->p2flux(['/v1/capabilities' => self::CAPABILITIES, '/v1/payments' => self::INTENT]);

        $response = $this->call_controller(CreateSponsoredPaymentController::class);

        $this->assertSame(['/v1/capabilities', '/v1/payments'], $this->calledPaths(), 'capabilities must be asked first');
        $this->assertSame('payment_token', $this->transport->calls[1]['payload']['gas_payment_mode']);
        $this->assertFalse($this->body($response)['buyer_needs_eth']);
    }

    #[Test]
    public function sponsorship_that_is_not_offered_falls_back_to_native(): void
    {
        $this->p2flux([
            '/v1/capabilities' => [200, ['chain_id' => 8453, 'tokens' => [[
                'symbol' => 'USDC',
                'gas_payment_modes' => ['native'],
                'operations' => ['one_time_payment' => false],
            ]]]],
            '/v1/payments' => self::INTENT,
        ]);

        $response = $this->call_controller(CreateSponsoredPaymentController::class);

        $this->assertSame('native', $this->transport->calls[1]['payload']['gas_payment_mode']);
        $this->assertTrue($this->body($response)['buyer_needs_eth']);
    }

    #[Test]
    public function a_valid_verdict_pays_the_order_and_a_repeat_changes_nothing(): void
    {
        $this->p2flux(['/v1/payments/verify' => [200, ['valid' => true, 'tx_hash' => '0xabc', 'settlement_receipt' => 'p2r2.x']]]);
        $order = $this->order();

        foreach (range(1, 3) as $ignored) {
            $response = $this->call_controller(VerifyPaymentController::class, ['order' => $order->id, 'tx_hash' => '0xabc']);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('paid', $this->body($response)['status']);
        }

        $this->assertSame('paid', $order->status);
        $this->assertCount(1, $order->writes, 'an order must be fulfilled exactly once, however often verification repeats');
        $this->assertCount(1, $this->transport->calls, 'a paid order must not be re-verified against the API');
    }

    #[Test]
    public function a_confirming_settlement_does_not_pay_the_order(): void
    {
        $this->p2flux(['/v1/payments/verify' => [200, ['valid' => false, 'code' => 'PAYMENT_CONFIRMING', 'tx_hash' => '0xabc']]]);
        $order = $this->order();

        $response = $this->call_controller(VerifyPaymentController::class, ['order' => $order->id, 'tx_hash' => '0xabc']);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('pending', $order->status);
        $this->assertSame([], $order->writes);
    }

    #[Test]
    public function a_transaction_that_settles_nothing_does_not_pay_the_order(): void
    {
        $this->p2flux(['/v1/payments/verify' => [200, ['valid' => false, 'code' => 'TRANSACTION_NOT_FOUND']]]);
        $order = $this->order();

        $response = $this->call_controller(VerifyPaymentController::class, ['order' => $order->id, 'tx_hash' => '0xbad']);

        $this->assertSame('unsettled', $this->body($response)['status']);
        $this->assertSame('pending', $order->status);
    }

    #[Test]
    public function a_malformed_response_does_not_pay_the_order(): void
    {
        $this->p2flux(['/v1/payments/verify' => [200, []]]);
        $order = $this->order();

        $this->call_controller(VerifyPaymentController::class, ['order' => $order->id, 'tx_hash' => '0xabc']);

        $this->assertSame('pending', $order->status);
    }

    #[Test]
    public function an_unreachable_api_leaves_the_order_unpaid_and_says_so(): void
    {
        $this->p2flux([], static function (): array {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER');
        });
        $order = $this->order();

        $response = $this->call_controller(VerifyPaymentController::class, ['order' => $order->id, 'tx_hash' => '0xabc']);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('unavailable', $this->body($response)['status']);
        $this->assertSame('pending', $order->status);
    }

    #[Test]
    public function a_claim_with_no_hash_recovers_the_settlement(): void
    {
        $this->p2flux(['/v1/payments/recover' => [200, ['found' => true, 'valid' => true, 'tx_hash' => '0xrec']]]);
        $order = $this->order();

        $response = $this->call_controller(VerifyPaymentController::class, ['order' => $order->id]);

        $this->assertSame('/v1/payments/recover', $this->calledPaths()[0]);
        $this->assertSame('paid', $this->body($response)['status']);
        $this->assertSame('0xrec', $order->tx_hash);
    }

    #[Test]
    public function a_recovery_that_finds_nothing_is_not_a_payment(): void
    {
        $this->p2flux(['/v1/payments/recover' => [404, ['found' => false, 'code' => 'PAYMENT_NOT_FOUND', 'as_of_block' => '99']]]);
        $order = $this->order();

        $this->call_controller(VerifyPaymentController::class, ['order' => $order->id]);

        $this->assertSame('pending', $order->status);
    }

    #[Test]
    public function an_unavailable_recovery_is_not_a_payment(): void
    {
        $this->p2flux(['/v1/payments/recover' => [503, ['error' => 'RECOVERY_UNAVAILABLE']]]);
        $order = $this->order();

        $response = $this->call_controller(VerifyPaymentController::class, ['order' => $order->id]);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('pending', $order->status);
    }
}
