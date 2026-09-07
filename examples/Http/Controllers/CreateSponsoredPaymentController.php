<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/**
 * A payment a buyer can complete holding USDC and no ETH.
 *
 * With `gas_payment_mode => 'payment_token'` the buyer signs a token authorization instead of
 * sending a transaction. The P2Flux relayer submits it and pays the Base network fee in ETH, and
 * the buyer reimburses that exact cost in USDC inside the same transaction. Nothing is waived: the
 * fee is quoted before the buyer signs, and they pay it in USDC rather than in ETH.
 */
final class CreateSponsoredPaymentController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $order = Order::create([
            'user_id' => $request->user()->id,
            'amount' => '12.50',
            'status' => 'pending',
        ]);

        try {
            /* Ask what this deployment supports, and never assume. A token that implements the right
             * standards on a network P2Flux has not deployed to reports false here, and the request
             * is refused with PAYMENT_TOKEN_GAS_UNSUPPORTED before a buyer sees anything.
             *
             * Capabilities change only when the deployment does, so cache them rather than asking
             * per checkout. */
            $sponsored = Cache::remember('p2flux.sponsors_usdc', now()->addHour(), function (): bool {
                $usdc = collect($this->p2flux->capabilities()['tokens'])
                    ->firstWhere('symbol', 'USDC');

                return $usdc !== null
                    && in_array('payment_token', $usdc['gas_payment_modes'], true)
                    && ($usdc['operations']['one_time_payment'] ?? false) === true;
            });

            // Fall back to 'native' when sponsorship is unavailable: the ordinary path, where the
            // buyer sends the transaction and pays the fee in ETH.
            $payment = $this->p2flux->createPayment([
                'recipient' => config('services.p2flux.recipient'),
                'amount' => $order->amount,
                'gas_payment_mode' => $sponsored ? 'payment_token' : 'native',
            ]);
        } catch (P2FluxException $e) {
            report($e);

            return response()->json(['error' => $e->status], 502);
        }

        $order->update(['p2flux_intent' => $payment['intent']]);

        // Everything downstream is unchanged: same checkout URL, same server-side verification. The
        // verdict will carry an `accounting` block naming every figure in USDC base units.
        return response()->json([
            'order' => $order->id,
            'buyer_needs_eth' => !$sponsored,
            'checkout' => config('services.p2flux.checkout_url') . '/#/pay/' . rawurlencode($payment['intent']),
        ]);
    }
}
