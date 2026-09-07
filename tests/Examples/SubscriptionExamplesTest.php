<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests\Examples;

use App\Console\Commands\ChargeDueSubscriptions;
use App\Console\Commands\RecoverPendingPayments;
use App\Http\Controllers\CancelSubscriptionController;
use App\Http\Controllers\RestoreAllowanceController;
use App\Http\Controllers\SubscriptionSignupController;
use App\Models\Subscription;
use P2Flux\P2FluxException;
use PHPUnit\Framework\Attributes\Test;

final class SubscriptionExamplesTest extends ExampleTestCase
{
    private const STATUS = [200, [
        'terms' => ['salt' => '12345', 'amount_units' => '5000000', 'recipient' => '0xEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE', 'period' => 2592000],
        'subscription_id' => '0x999',
        'period_index' => 3,
    ]];

    #[Test]
    public function signup_creates_terms_and_never_returns_a_capability(): void
    {
        $this->p2flux(['/v1/subscriptions' => [200, ['setup_token' => 'p2setup2.k1.stub.mac', 'salt' => '12345']]]);

        $response = $this->call_controller(SubscriptionSignupController::class, [], 'create');

        $this->assertStringContainsString('#/subscribe/', $this->body($response)['checkout']);
        $this->assertSame('12345', Subscription::all()[0]->salt);
        $this->assertNoCapabilityLeaked($response);
    }

    #[Test]
    public function a_capability_is_stored_only_when_it_matches_this_order(): void
    {
        $this->p2flux(['/v1/subscriptions/status' => self::STATUS]);
        $subscription = Subscription::create(['user_id' => 7, 'status' => 'pending', 'salt' => '12345']);

        $response = $this->call_controller(SubscriptionSignupController::class, [
            'subscription' => $subscription->id,
            'capability' => 'p2s2.k1.stub.mac',
        ], 'store');

        $this->assertSame('active', $subscription->status);
        $this->assertSame('p2s2.k1.stub.mac', $subscription->capability);
        $this->assertNoCapabilityLeaked($response);
    }

    #[Test]
    public function a_capability_from_a_different_setup_is_refused(): void
    {
        $this->p2flux(['/v1/subscriptions/status' => self::STATUS]);
        $subscription = Subscription::create(['user_id' => 7, 'status' => 'pending', 'salt' => 'a-different-salt']);

        $response = $this->call_controller(SubscriptionSignupController::class, [
            'subscription' => $subscription->id,
            'capability' => 'p2s2.k1.somebody-elses.mac',
        ], 'store');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('pending', $subscription->status);
        $this->assertNull($subscription->capability, 'a mismatched capability must never be stored');
    }

    #[Test]
    public function a_charged_period_is_recorded_with_its_transaction(): void
    {
        $this->p2flux(['/v1/charges' => [200, [
            'status' => 'CHARGED', 'tx_hash' => '0xcharged', 'period_index' => 3, 'subscription_id' => '0x' . str_repeat('9', 64),
        ]]]);
        $subscription = $this->subscription();

        $this->assertSame(0, $this->runCommand(ChargeDueSubscriptions::class));

        $this->assertSame('0xcharged', $subscription->tx_hash);
        $this->assertSame(3, $subscription->paid_period_index);
        $this->assertSame(4, $subscription->period_index, 'the schedule must advance');
        $this->assertFalse($subscription->evidence_pending);
    }

    #[Test]
    public function already_charged_records_the_collection_and_recovers_the_evidence(): void
    {
        $this->p2flux([
            '/v1/charges/recover' => [200, ['found' => true, 'tx_hash' => '0xrecovered', 'period_index' => 3]],
            '/v1/charges' => [200, ['status' => 'ALREADY_CHARGED', 'period_index' => 3, 'subscription_id' => '0x' . str_repeat('9', 64)]],
        ]);
        $subscription = $this->subscription();

        $this->runCommand(ChargeDueSubscriptions::class);

        $this->assertSame(3, $subscription->paid_period_index, 'ALREADY_CHARGED means the period was collected');
        $this->assertSame('0xrecovered', $subscription->tx_hash);
        $this->assertFalse($subscription->evidence_pending);
    }

    #[Test]
    public function already_charged_for_a_different_period_changes_nothing(): void
    {
        /* The answer must be about the period this application expected. Marking a different period
         * paid on the strength of it would lose a real collection and invent another. */
        $this->p2flux(['/v1/charges' => [200, ['status' => 'ALREADY_CHARGED', 'period_index' => 11, 'subscription_id' => '0x' . str_repeat('9', 64)]]]);
        $subscription = $this->subscription();

        $this->runCommand(ChargeDueSubscriptions::class);

        $this->assertSame('needs_review', $subscription->status);
        $this->assertNull($subscription->paid_period_index);
        $this->assertSame(3, $subscription->period_index, 'the schedule must not advance on a mismatch');
    }

    #[Test]
    public function a_recovery_failure_keeps_the_collection_and_does_not_abort_the_run(): void
    {
        $charged = 0;
        $this->p2flux([], function (string $url, array $payload, int $timeout) use (&$charged): array {
            if (str_ends_with($url, '/v1/charges/recover')) {
                throw new P2FluxException('RECOVERY_UNAVAILABLE', 'RETRY_LATER');
            }
            $charged++;

            return [200, ['status' => 'ALREADY_CHARGED', 'period_index' => 3, 'subscription_id' => '0x' . str_repeat('9', 64)]];
        });

        $first = $this->subscription();
        $second = $this->subscription();

        $this->assertSame(0, $this->runCommand(ChargeDueSubscriptions::class));

        foreach ([$first, $second] as $subscription) {
            $this->assertSame(3, $subscription->paid_period_index, 'the collection is a fact even without evidence');
            $this->assertTrue($subscription->evidence_pending);
            $this->assertNull($subscription->tx_hash);
        }
        $this->assertSame(2, $charged, 'one subscription failing recovery must not abandon the others');
    }

    #[Test]
    public function the_sweep_fills_in_evidence_that_was_pending(): void
    {
        $this->p2flux(['/v1/charges/recover' => [200, ['found' => true, 'tx_hash' => '0xlate', 'period_index' => 3]]]);
        $subscription = $this->subscription(['evidence_pending' => true, 'paid_period_index' => 3]);

        $this->runCommand(RecoverPendingPayments::class);

        $this->assertSame('0xlate', $subscription->tx_hash);
        $this->assertFalse($subscription->evidence_pending);
    }

    #[Test]
    public function a_customer_problem_stops_neither_the_run_nor_the_subscription(): void
    {
        $this->p2flux(['/v1/charges' => [402, ['error' => 'INSUFFICIENT_BALANCE']]]);
        $subscription = $this->subscription();

        $this->runCommand(ChargeDueSubscriptions::class);

        $this->assertSame('needs_customer', $subscription->status);
        $this->assertNull($subscription->paid_period_index);
    }

    #[Test]
    public function a_revoked_subscription_is_stopped(): void
    {
        $this->p2flux(['/v1/charges' => [403, ['error' => 'PERMISSION_REVOKED']]]);
        $subscription = $this->subscription();

        $this->runCommand(ChargeDueSubscriptions::class);

        $this->assertSame('stopped', $subscription->status);
    }

    #[Test]
    public function an_unreachable_api_leaves_the_period_open(): void
    {
        $this->p2flux([], static function (): array {
            throw new P2FluxException('NETWORK_ERROR', 'RETRY_LATER');
        });
        $subscription = $this->subscription();

        $this->assertSame(0, $this->runCommand(ChargeDueSubscriptions::class));

        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->paid_period_index);
        $this->assertSame(3, $subscription->period_index);
    }

    #[Test]
    public function cancellation_hands_the_browser_a_session_and_not_the_capability(): void
    {
        $this->p2flux(['/v1/subscriptions/revoke/session' => [200, ['cancel_token' => 'p2cancel1.k1.stub.mac', 'expires_at' => 1]]]);
        $subscription = $this->subscription();

        $response = $this->call_controller(CancelSubscriptionController::class, ['subscription' => $subscription->id], 'revoke');

        $this->assertStringContainsString('#/cancel/', $this->body($response)['cancel_page']);
        $this->assertNoCapabilityLeaked($response);
    }

    #[Test]
    public function stopping_collection_needs_no_api_call_at_all(): void
    {
        $this->p2flux([]);
        $subscription = $this->subscription();

        $response = $this->call_controller(CancelSubscriptionController::class, ['subscription' => $subscription->id], 'stop');

        $this->assertSame('cancelled', $subscription->status);
        $this->assertSame([], $this->calledPaths(), 'P2Flux has nothing to be told when a merchant stops billing');
        $this->assertNoCapabilityLeaked($response);
    }

    #[Test]
    public function restoring_an_allowance_hands_the_browser_an_approve_session(): void
    {
        $this->p2flux(['/v1/allowances/restore/session' => [200, ['approve_token' => 'p2approve1.k1.stub.mac', 'expires_at' => 1]]]);
        $subscription = $this->subscription();

        $response = $this->call_controller(RestoreAllowanceController::class, ['subscription' => $subscription->id]);

        $this->assertStringContainsString('#/approve/', $this->body($response)['approve_page']);
        $this->assertNoCapabilityLeaked($response);
    }
}
