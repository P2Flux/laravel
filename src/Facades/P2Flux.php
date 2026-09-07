<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use P2Flux\P2FluxClient;

/**
 * Optional convenience over the container binding.
 *
 * Import it explicitly - `use P2Flux\Laravel\Facades\P2Flux;` - because this package registers no
 * global alias. Constructor injection of `P2Flux\P2FluxClient` stays the recommended interface: it
 * is what makes a class testable without touching a facade root.
 *
 * Every call below is forwarded to the same singleton the container holds. There is no second
 * implementation here to drift from the SDK.
 *
 * @method static array<string, mixed> capabilities()
 * @method static array<string, mixed> createPayment(array<string, mixed> $terms)
 * @method static array<string, mixed> resolvePayment(string $intent)
 * @method static array<string, mixed> verifyPayment(string $intent, string $txHash, ?string $settlementReceipt = null)
 * @method static array<string, mixed> recoverPayment(string $intent)
 * @method static array<string, mixed> sponsorPayment(string $intent, string $quote, string $payer, string $signature)
 * @method static array<string, mixed> createSubscription(array<string, mixed> $terms)
 * @method static array<string, mixed> resolveSubscription(string $setupToken, ?string $gasPaymentMode = null, ?string $payer = null)
 * @method static array<string, mixed> finalizeSubscription(string $setupToken, string $payer, string $signature, array<string, string>|null $sponsorship = null)
 * @method static \P2Flux\ChargeResult charge(string $subscriptionRef)
 * @method static array<string, mixed> status(string $subscriptionRef)
 * @method static array<string, mixed> recoverCharge(string $subscriptionRef, int $periodIndex, array{attempted_at?: int, block?: int}|null $hint = null)
 * @method static array<string, mixed> createCancellationSession(string $subscriptionRef)
 * @method static array<string, mixed> prepareSubscriptionCancellation(string $subscriptionRef)
 * @method static array<string, mixed> prepareAllowanceRevocation()
 * @method static array<string, mixed> createAllowanceRestoreSession(string $subscriptionRef)
 * @method static array<string, mixed> resolveAllowanceRestore(string $approveToken, ?string $gasPaymentMode = null)
 * @method static array<string, mixed> submitAllowanceRestore(string $approveToken, string $quote, string $permitSignature, string $networkFeeSignature, ?string $allowanceUnits = null, ?string $permitNonce = null)
 * @method static array<string, mixed> prepareRefund(array<string, mixed> $original, string $amountUnits)
 * @method static array<string, mixed> verifyRefund(array<string, mixed> $original, string $amountUnits, string $refundTxHash)
 * @method static array<string, mixed> resolveRefund(string $refundToken)
 *
 * @see \P2Flux\P2FluxClient
 */
final class P2Flux extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return P2FluxClient::class;
    }
}
