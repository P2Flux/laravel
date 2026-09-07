<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests\Examples;

use App\Http\Controllers\RefundController;
use PHPUnit\Framework\Attributes\Test;

final class RefundExamplesTest extends ExampleTestCase
{
    private const PREPARED = [200, [
        'refund_token' => 'p2refund1.k1.stub.mac',
        'refund_amount' => '2.500000',
        'merchant' => '0xmerchant',
        'payer' => '0xpayer',
    ]];

    #[Test]
    public function preparing_a_refund_reserves_the_order_first(): void
    {
        $this->p2flux(['/v1/refunds/prepare' => self::PREPARED]);
        $order = $this->order(['status' => 'paid', 'tx_hash' => '0xpaid']);

        $response = $this->call_controller(RefundController::class, ['order' => $order->id, 'amount_units' => '2500000'], 'prepare');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($order->refund_reserved_at, 'the reservation must exist before P2Flux is asked');
        $this->assertStringContainsString('#/refund/', $this->body($response)['refund_page']);
    }

    #[Test]
    public function a_second_request_cannot_prepare_a_second_refund(): void
    {
        /* P2Flux keeps no refund history, so preparing twice happily prepares two valid refunds.
         * The reservation is the only thing standing between a support double-click and paying a
         * customer twice. */
        $this->p2flux(['/v1/refunds/prepare' => self::PREPARED]);
        $order = $this->order(['status' => 'paid', 'tx_hash' => '0xpaid']);

        $first = $this->call_controller(RefundController::class, ['order' => $order->id, 'amount_units' => '2500000'], 'prepare');
        $second = $this->call_controller(RefundController::class, ['order' => $order->id, 'amount_units' => '2500000'], 'prepare');

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(409, $second->getStatusCode());
        $this->assertCount(1, $this->transport->calls, 'the second attempt must never reach P2Flux');
    }

    #[Test]
    public function a_failed_prepare_releases_the_reservation(): void
    {
        /* Preparing can fail transiently. A reservation left behind would make this order
         * permanently un-refundable - a worse outcome than the failure, and a silent one. */
        $this->p2flux(['/v1/refunds/prepare' => [503, ['error' => 'RETRY_LATER', 'action' => 'RETRY_LATER']]]);
        $order = $this->order(['status' => 'paid', 'tx_hash' => '0xpaid']);

        $response = $this->call_controller(RefundController::class, ['order' => $order->id, 'amount_units' => '2500000'], 'prepare');

        $this->assertSame(502, $response->getStatusCode());
        $this->assertNull($order->refund_reserved_at, 'a failed prepare must not strand the order');

        // And the retry works.
        $this->p2flux(['/v1/refunds/prepare' => self::PREPARED]);
        $retry = $this->call_controller(RefundController::class, ['order' => $order->id, 'amount_units' => '2500000'], 'prepare');
        $this->assertSame(200, $retry->getStatusCode());
    }

    #[Test]
    public function an_unpaid_order_cannot_be_refunded(): void
    {
        $this->p2flux(['/v1/refunds/prepare' => self::PREPARED]);
        $order = $this->order(['status' => 'pending']);

        $response = $this->call_controller(RefundController::class, ['order' => $order->id, 'amount_units' => '2500000'], 'prepare');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame([], $this->calledPaths());
    }

    #[Test]
    public function a_settled_refund_is_recorded(): void
    {
        $this->p2flux(['/v1/refunds/verify' => [200, ['status' => 'REFUNDED', 'refund_tx_hash' => '0xrefund']]]);
        $order = $this->order(['status' => 'paid', 'tx_hash' => '0xpaid']);

        $response = $this->call_controller(RefundController::class, [
            'order' => $order->id,
            'amount_units' => '2500000',
            'refund_tx_hash' => '0xrefund',
        ], 'verify');

        $this->assertSame('refunded', $order->status);
        $this->assertSame('0xrefund', $order->refund_tx_hash);
        $this->assertSame('refunded', $this->body($response)['status']);
    }

    #[Test]
    public function a_confirming_refund_is_not_recorded_as_refunded(): void
    {
        $this->p2flux(['/v1/refunds/verify' => [409, ['code' => 'REFUND_CONFIRMING', 'tx_hash' => '0xrefund']]]);
        $order = $this->order(['status' => 'paid', 'tx_hash' => '0xpaid']);

        $response = $this->call_controller(RefundController::class, [
            'order' => $order->id,
            'amount_units' => '2500000',
            'refund_tx_hash' => '0xrefund',
        ], 'verify');

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('paid', $order->status, 'a confirming refund must not be booked yet');
    }
}
