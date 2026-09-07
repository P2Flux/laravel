<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/**
 * The sweep that catches what the browser lost.
 *
 * A popup closes, a tab crashes, a callback drops: the buyer paid and your page never heard.
 * recoverPayment() finds the settlement from the intent alone. Pure reads, idempotent.
 *
 *     Schedule::command('p2flux:recover-pending')->everyFifteenMinutes();
 */
final class RecoverPendingPayments extends Command
{
    protected $signature = 'p2flux:recover-pending';

    protected $description = 'Find settlements for orders whose callback never arrived';

    public function handle(P2FluxClient $p2flux): int
    {
        $stale = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('p2flux_intent')
            ->where('created_at', '<', now()->subMinutes(10))
            ->get();

        foreach ($stale as $order) {
            try {
                $found = $p2flux->recoverPayment($order->p2flux_intent);
            } catch (P2FluxException $e) {
                $this->line("unavailable for {$order->id}: {$e->status}");
                continue;
            }

            if (($found['found'] ?? false) !== true) {
                /* PAYMENT_NOT_FOUND is a statement about one block height, not a permanent verdict:
                 * a slow wallet can still settle. Stop on your own business rules - an age cutoff -
                 * and never mint a second intent for the same order. */
                continue;
            }

            if (($found['valid'] ?? false) !== true) {
                $this->line("confirming {$order->id}: {$found['tx_hash']}");
                continue;
            }

            // The same fulfil-once transition the verify endpoint uses.
            DB::transaction(function () use ($order, $found): void {
                $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
                if ($fresh->status === 'paid') {
                    return;
                }
                $fresh->update(['status' => 'paid', 'tx_hash' => $found['tx_hash']]);
            });

            $this->info("RECOVERED {$order->id}: {$found['tx_hash']}");
        }

        return self::SUCCESS;
    }
}
