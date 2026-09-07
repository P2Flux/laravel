<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;
use P2Flux\P2FluxClient;

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

            if ($result->ok && $result->txHash !== null) {
                $subscription->markPeriodPaid($result->periodIndex, $result->txHash);
                $this->info("CHARGED {$subscription->id} period {$result->periodIndex}");
                continue;
            }

            if ($result->ok) {
                /* ALREADY_CHARGED: the period is collected and names no transaction - the normal
                 * answer to a retry after a timeout. Recover the settlement, because without it the
                 * period cannot be attributed, audited or refunded. */
                $found = $p2flux->recoverCharge($subscription->capability, (int) $result->periodIndex);
                $subscription->markPeriodPaid($result->periodIndex, $found['tx_hash'] ?? null);
                $this->info("ALREADY {$subscription->id} period {$result->periodIndex}");
                continue;
            }

            if ($result->status === 'CONFIRMING') {
                // On chain, not settled deep enough. Keep the period open, change nothing, ask again.
                $this->line("CONFIRMING {$subscription->id}");
                continue;
            }

            match ($result->action) {
                'RETRY_LATER' => $this->line("RETRY {$subscription->id}: {$result->status}"),
                'CUSTOMER_ACTION_REQUIRED' => $subscription->needsCustomer($result->status),
                'STOP_SUBSCRIPTION' => $subscription->stop($result->status),
                default => $this->error("NEEDS A HUMAN {$subscription->id}: {$result->status}"),
            };
        }

        return self::SUCCESS;
    }
}
