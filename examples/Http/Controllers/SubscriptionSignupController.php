<?php

declare(strict_types=1);

// MERCHANT APPLICATION CODE - an example, not part of the p2flux/laravel package.

namespace App\Http\Controllers;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use P2Flux\P2FluxClient;
use P2Flux\P2FluxException;

final class SubscriptionSignupController extends Controller
{
    public function __construct(private readonly P2FluxClient $p2flux)
    {
    }

    /** Step 1: create the terms and send the customer to the hosted checkout. */
    public function create(Request $request): JsonResponse
    {
        try {
            $setup = $this->p2flux->createSubscription([
                'recipient' => config('services.p2flux.recipient'),
                'amount' => '5.00',
                'period' => 30 * 86400,   // SECONDS
            ]);
        } catch (P2FluxException $e) {
            return response()->json(['error' => $e->status], 502);
        }

        // Keep the salt with the pending row: it is how you prove later that the capability you were
        // handed came from this exact setup.
        $subscription = Subscription::create([
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'salt' => $setup['salt'],
        ]);

        return response()->json([
            'subscription' => $subscription->id,
            'checkout' => config('services.p2flux.checkout_url') . '/#/subscribe/' . rawurlencode($setup['setup_token']),
        ]);
    }

    /** Step 2: the checkout posts the capability back. Prove it, then store it encrypted. */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'subscription' => ['required', 'integer'],
            'capability' => ['required', 'string'],
        ]);

        $subscription = Subscription::where('user_id', $request->user()->id)->findOrFail($input['subscription']);

        try {
            // A cryptographically valid capability can still be the WRONG one. Read the terms back
            // from the chain and compare them to what you sold.
            $state = $this->p2flux->status($input['capability']);
        } catch (P2FluxException $e) {
            return response()->json(['error' => $e->status], 502);
        }

        if ($state['terms']['salt'] !== $subscription->salt
            || strtolower($state['terms']['recipient']) !== strtolower((string) config('services.p2flux.recipient'))) {
            return response()->json(['error' => 'SETUP_MISMATCH'], 422);
        }

        /* The capability is a bearer credential: whoever holds it can collect the customer's next
         * period. Encrypt it at rest, keep it server-side, and never log it or put it in a URL. An
         * `encrypted` cast on the model does this for you. */
        $subscription->update([
            'status' => 'active',
            'capability' => $input['capability'],
            'p2flux_subscription_id' => $state['subscription_id'] ?? null,
            'period_seconds' => $state['terms']['period'],
            'period_index' => $state['period_index'] ?? 0,
            'next_charge_at' => now(),
        ]);

        // Never return the capability. It can charge, so it belongs in your database and nowhere else.
        return response()->json(['status' => 'active', 'subscription' => $subscription->id]);
    }
}
