<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/**
 * Your renewal job. P2Flux has no scheduler and no idea when a renewal is due.
 *
 * The package registers nothing like this: installing a Composer package must never start billing.
 * Schedule it yourself, in routes/console.php:
 *
 *     Schedule::command('p2flux:charge-due')->hourly()->withoutOverlapping();
 */
final class ChargeDueSubscriptions extends Command
{
    protected $signature = 'p2flux:charge-due {--limit=100}';

    protected $description = 'Collect every subscription period this application says is due';

    public function handle(P2FluxClient $p2flux): int
    {
        $due = Subscription::query()
            ->where('status', 'active')
            ->where('next_charge_at', '<=', now())
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $subscription) {
            // charge() never throws on a payment outcome, so there is nothing to catch here: every
            // branch below is an answer. Classify on ->action, not on ->status, so a code this
            // client has never seen still lands in the right branch.
            $result = $p2flux->charge($subscription->capability);

            if ($result->ok && !$this->isTheExpectedPeriod($subscription, $result)) {
                /* The answer must be about the period this application believes it is collecting.
                 * A mismatch means the stored capability and the local schedule disagree - never a
                 * reason to mark anything paid, and always a reason for a human to look. */
                $subscription->update(['status' => 'needs_review', 'last_status' => 'PERIOD_MISMATCH']);
                $this->error("MISMATCH {$subscription->id}: expected period {$subscription->period_index}, got {$result->periodIndex}");
                continue;
            }

            if ($result->ok && $result->txHash !== null) {
                $this->markPaid($subscription, (int) $result->periodIndex, $result->txHash);
                $this->info("CHARGED {$subscription->id} period {$result->periodIndex}");
                continue;
            }

            if ($result->ok) {
                /* ALREADY_CHARGED: the period is collected and names no transaction - the normal
                 * answer to a retry after a timeout. The collection is a fact from this moment on,
                 * so it is recorded whatever happens next; recovery only enriches it with evidence.
                 *
                 * Recovery is attempted here and allowed to fail: it can be briefly unavailable, and
                 * losing the whole renewal pass - every other customer's period - over a missing
                 * transaction hash would be the worse trade. The recovery sweep fills it in later.
                 */
                $txHash = null;
                try {
                    $found = $p2flux->recoverCharge($subscription->capability, (int) $result->periodIndex);
                    $txHash = ($found['found'] ?? false) === true ? ($found['tx_hash'] ?? null) : null;
                } catch (P2FluxException $e) {
                    $this->line("evidence pending for {$subscription->id}: {$e->status}");
                }

                $this->markPaid($subscription, (int) $result->periodIndex, $txHash);
                $this->info("ALREADY {$subscription->id} period {$result->periodIndex}" . ($txHash === null ? ' (evidence pending)' : ''));
                continue;
            }

            if ($result->status === 'CONFIRMING') {
                // On chain, not settled deep enough. Keep the period open, change nothing, ask again.
                $this->line("CONFIRMING {$subscription->id}");
                continue;
            }

            match ($result->action) {
                'RETRY_LATER' => $this->line("RETRY {$subscription->id}: {$result->status}"),
                'CUSTOMER_ACTION_REQUIRED' => $subscription->update(['status' => 'needs_customer', 'last_status' => $result->status]),
                'STOP_SUBSCRIPTION' => $subscription->update(['status' => 'stopped', 'last_status' => $result->status]),
                default => $this->error("NEEDS A HUMAN {$subscription->id}: {$result->status}"),
            };
        }

        return self::SUCCESS;
    }

    /** The result must name the subscription and the period this application expected. */
    private function isTheExpectedPeriod(Subscription $subscription, \P2Flux\ChargeResult $result): bool
    {
        if ($result->periodIndex === null || (int) $result->periodIndex !== (int) $subscription->period_index) {
            return false;
        }

        return $result->subscriptionId === null
            || $result->subscriptionId === $subscription->p2flux_subscription_id;
    }

    /**
     * Records the collection. `evidence_pending` says the money moved but the transaction hash is
     * not known yet - never that the period might be unpaid.
     */
    private function markPaid(Subscription $subscription, int $periodIndex, ?string $txHash): void
    {
        $subscription->update([
            'status' => 'active',
            'last_status' => 'PAID',
            'paid_period_index' => $periodIndex,
            'tx_hash' => $txHash,
            'evidence_pending' => $txHash === null,
            'period_index' => $periodIndex + 1,
            'next_charge_at' => now()->addSeconds((int) $subscription->period_seconds),
        ]);
    }
}
