<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

final class CreatePaymentController extends Controller
{
    // The container binds P2FluxClient; nothing else to wire.
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
            /* The recipient and the amount come from your configuration and your own records - never
             * from the request body. A browser must not be able to choose who gets paid. */
            $payment = $this->p2flux->createPayment([
                'recipient' => config('services.p2flux.recipient'),
                'amount' => $order->amount,
            ]);
        } catch (P2FluxException $e) {
            report($e);

            return response()->json(['error' => $e->status], 502);
        }

        // Store the intent beside your own reference: verification and recovery both need it, and
        // recovery still works after the intent expires.
        $order->update(['p2flux_intent' => $payment['intent']]);

        return response()->json([
            'order' => $order->id,
            'checkout' => config('services.p2flux.checkout_url') . '/#/pay/' . rawurlencode($payment['intent']),
        ]);
    }
}
