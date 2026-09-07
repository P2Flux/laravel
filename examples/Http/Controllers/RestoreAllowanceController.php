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
 * INSUFFICIENT_ALLOWANCE is not a dead subscription.
 *
 * The authorization the customer signed is intact and you can still collect; what ran short is the
 * ERC-20 allowance, and the fix is one approve() from the customer's own wallet. No new signature,
 * no new subscription, and afterwards you charge the SAME capability again.
 */
final class RestoreAllowanceController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->findOrFail($request->integer('subscription'));

        try {
            /* The narrowest token P2Flux issues: the payer, the spender, the token and how much the
             * next charge pulls. It cannot charge, cannot revoke and cannot refund - which is why it
             * is safe to put in a URL the customer opens, and the capability is not. */
            $session = $this->p2flux->createAllowanceRestoreSession($subscription->capability);
        } catch (P2FluxException $e) {
            return response()->json(['error' => $e->status], 502);
        }

        return response()->json([
            'approve_page' => config('services.p2flux.checkout_url') . '/#/approve/' . rawurlencode($session['approve_token']),
            'expires_at' => $session['expires_at'] ?? null,
        ]);
        // The checkout posts `p2flux.allowance.restored`; then charge() the same subscription again.
    }
}
