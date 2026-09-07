<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

/**
 * Cancelling is two different things, and a customer should be offered both.
 *
 * Stopping collection is entirely yours: stop calling charge(). P2Flux needs no notification and
 * has nothing to notify. Revoking the on-chain authorization is the customer's own transaction -
 * P2Flux cannot revoke wallet authority and does not pretend to.
 */
final class CancelSubscriptionController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    /** Stop billing. Nothing on chain changes; the customer's authorization simply goes unused. */
    public function stop(Request $request): JsonResponse
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->findOrFail($request->integer('subscription'));

        $subscription->update(['status' => 'cancelled', 'last_status' => 'CANCELLED_BY_MERCHANT']);

        return response()->json(['status' => 'cancelled']);
    }

    /** Offer the customer the revocation their own wallet must send. */
    public function revoke(Request $request): JsonResponse
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->findOrFail($request->integer('subscription'));

        try {
            /* The session token is the narrow thing that may reach a browser: it can build the
             * customer's revoke() and nothing else. The p2s2 capability must never be sent - it can
             * charge, and a page that holds it has handed that power to anyone who can read it. */
            $session = $this->p2flux->createCancellationSession($subscription->capability);
        } catch (P2FluxException $e) {
            return response()->json(['error' => $e->status], 502);
        }

        return response()->json([
            'cancel_page' => config('services.p2flux.checkout_url') . '/#/cancel/' . rawurlencode($session['cancel_token']),
            'expires_at' => $session['expires_at'] ?? null,
        ]);
    }
}
