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
 * A refund is a plain USDC transfer from YOUR wallet back to the wallet that paid. P2Flux derives
 * the payer and the refundable maximum from the original settlement, never holds the money, and
 * charges nothing for it.
 *
 * P2Flux keeps NO refund history, so one-refund-per-payment is your record to enforce - and the safe
 * place is BEFORE prepare.
 */
final class RefundController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    public function prepare(Request $request): JsonResponse
    {
        $order = Order::findOrFail($request->integer('order'));

        // Reserve first: preparing twice happily prepares two valid refunds.
        $reserved = DB::transaction(function () use ($order): bool {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if ($fresh->status !== 'paid' || $fresh->refund_reserved_at !== null) {
                return false;
            }
            $fresh->update(['refund_reserved_at' => now()]);

            return true;
        });

        if (!$reserved) {
            return response()->json(['error' => 'ALREADY_REFUNDED_OR_UNPAID'], 409);
        }

        try {
            // Amounts are micro-USDC integer strings: "2500000" is 2.50 USDC.
            $prep = $this->p2flux->prepareRefund(
                ['intent' => $order->p2flux_intent, 'tx_hash' => $order->tx_hash],
                $request->string('amount_units')->toString(),
            );
        } catch (P2FluxException $e) {
            /* Release the reservation. Preparing can fail transiently - a 5xx, an unreachable API -
             * and a reservation left behind would make this order permanently un-refundable, which
             * is a worse outcome than the failure itself. Nothing was prepared, so nothing is at
             * risk of being double-refunded by releasing it. */
            $order->update(['refund_reserved_at' => null]);

            return response()->json(['error' => $e->status], 502);
        }

        return response()->json([
            'refund_page' => config('services.p2flux.checkout_url') . '/#/refund/' . rawurlencode($prep['refund_token']),
            'send' => ['from' => $prep['merchant'], 'to' => $prep['payer'], 'amount' => $prep['refund_amount']],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $order = Order::findOrFail($request->integer('order'));

        try {
            // From the ORIGINAL settlement, not the prepare token: this still works days later.
            $verdict = $this->p2flux->verifyRefund(
                ['intent' => $order->p2flux_intent, 'tx_hash' => $order->tx_hash],
                $request->string('amount_units')->toString(),
                $request->string('refund_tx_hash')->toString(),
            );
        } catch (P2FluxException $e) {
            return response()->json(['error' => $e->status], 502);
        }

        if (($verdict['status'] ?? '') === 'REFUNDED') {
            $order->update(['status' => 'refunded', 'refund_tx_hash' => $verdict['refund_tx_hash']]);

            return response()->json(['status' => 'refunded']);
        }

        // REFUND_CONFIRMING: poll the SAME hash. Never send another transfer.
        return response()->json(['status' => 'confirming'], 202);
    }
}
