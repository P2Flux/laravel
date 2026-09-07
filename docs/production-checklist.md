# Production checklist

Short, and every line is something that has gone wrong in a real integration.

## Money

- [ ] **Payments are created in Laravel**, with the recipient and amount from config and your own
      records — never from a request body. → [Payments](payments.md)
- [ ] **Nothing is fulfilled on a browser message.** `p2flux.payment.completed` is a claim; your
      `verifyPayment()` verdict is the decision. There are no webhooks to wait for.
- [ ] **The paid transition happens once**, inside `DB::transaction()` with `lockForUpdate()` and the
      status re-checked inside the lock. Repeat callbacks are normal.
- [ ] **Your own reference is stored with the intent**, before the buyer leaves the page.
- [ ] **`CONFIRMING` is neither failure nor success.** Poll the same hash.
- [ ] **`ALREADY_CHARGED` is a success** — the normal answer to a retry after a timeout.
      → [Subscriptions](subscriptions.md)
- [ ] **One refund per payment is enforced by you**, reserved before `prepareRefund()`.

## Secrets

- [ ] **The `p2s2` capability is encrypted at rest** (an `encrypted` cast) and never leaves the
      server. It can charge.
- [ ] **Logs carry identifiers, not tokens.** Order ids and transaction hashes are useful; intents,
      setup tokens, capabilities and session tokens must be redacted.
- [ ] There is **no API key** to leak, because P2Flux v1 has no API authentication.

## Configuration

- [ ] **`config:cache` is part of your deploy** and was tested. This package reads its own defaults
      when config is cached, so a cached app without a published config still works.
- [ ] **The environment is stored per order.** Test tokens are refused by production and the reverse.
- [ ] **`P2FLUX_TIMEOUT` is deliberate.** The default is 60 seconds because a charge waits for
      confirmation.
- [ ] **`capabilities()` is checked and cached** before offering the USDC network-fee option.
      → [Paying the network fee in USDC](network-fee-in-usdc.md)

## Failure paths

- [ ] **Errors are classified on `action`, not `status`.**
- [ ] **An unreachable API is "unknown", never "declined".** Never cancel a subscription on it.
- [ ] **A recovery sweep exists**: a scheduled command calling `recoverPayment()` over long-pending
      orders, and `recoverCharge()` for periods that answered `ALREADY_CHARGED`.
- [ ] **Retries are bounded and honour `retry_after`.**
- [ ] **The renewal command uses `withoutOverlapping()`**, so two workers do not race the same row.

## Before launch

- [ ] The whole flow ran against `https://api-test.p2flux.com` on Base Sepolia.
- [ ] Your verify endpoint was called twice with the same payload, and fulfilled once.
- [ ] Your renewal job ran twice in the same period, and charged once.
- [ ] → [Testing](testing.md) covers all three offline.
