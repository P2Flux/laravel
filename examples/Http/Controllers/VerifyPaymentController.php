<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/**
 * The trust boundary.
 *
 * P2Flux sends no webhooks. The browser posts what the hosted checkout told it, and that is a claim.
 * This action checks the claim against the chain and is the only place an order becomes paid.
 */
final class VerifyPaymentController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'order' => ['required', 'integer'],
            'tx_hash' => ['nullable', 'string'],
            'settlement_receipt' => ['nullable', 'string'],
        ]);

        $order = Order::where('user_id', $request->user()->id)->findOrFail($input['order']);

        // Already settled by an earlier call: answer the same thing again and touch nothing.
        if ($order->status === 'paid') {
            return response()->json(['status' => 'paid', 'tx_hash' => $order->tx_hash]);
        }

        try {
            // No hash in the claim (a dead callback): the intent alone can still find the settlement.
            $verdict = empty($input['tx_hash'])
                ? $this->p2flux->recoverPayment($order->p2flux_intent)
                : $this->p2flux->verifyPayment(
                    $order->p2flux_intent,
                    $input['tx_hash'],
                    $input['settlement_receipt'] ?? null,
                );
        } catch (P2FluxException $e) {
            // Never reached a verdict. Unknown, not rejected - the caller retries.
            return response()->json(['status' => 'unavailable', 'code' => $e->status], 503);
        }

        if (($verdict['valid'] ?? false) !== true) {
            return ($verdict['code'] ?? '') === 'PAYMENT_CONFIRMING'
                ? response()->json(['status' => 'confirming'], 202)
                : response()->json(['status' => 'unsettled', 'code' => $verdict['code'] ?? null]);
        }

        /* Fulfil exactly once. Re-check the status INSIDE the lock: two tabs, a retry racing a cron
         * sweep, and a manual "check again" button all arrive here, and only one may ship goods. */
        DB::transaction(function () use ($order, $verdict): void {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($fresh->status === 'paid') {
                return;
            }

            $fresh->update([
                'status' => 'paid',
                'tx_hash' => $verdict['tx_hash'],
                'settlement_receipt' => $verdict['settlement_receipt'] ?? null,
            ]);

            // Dispatch fulfilment here, so it happens once, with the status change.
        });

        return response()->json(['status' => 'paid', 'tx_hash' => $verdict['tx_hash']]);
    }
}
