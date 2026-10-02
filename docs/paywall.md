# Charge AI agents (x402 paywall)

The `p2flux.paywall` middleware charges AI agents for a route, in USDC, over x402. Live on Base
Mainnet and Base Sepolia. It is the PHP SDK's `P2Flux\Paywall` with Laravel's request, response and
cache. No x402 library, no account, no API key.

## Setup

```dotenv
P2FLUX_RECIPIENT=0xYourWallet          # your wallet on Base; every payment goes to it
P2FLUX_PAYWALL_PRICE=0.05              # USDC per request, at least 0.01. Default 0.05
P2FLUX_PAYWALL_PREPAID=true            # offer the prepaid balance too. Default true
P2FLUX_PAYWALL_ON_UNAVAILABLE=refuse   # refuse or free. Default refuse
```

| Config key | Environment | Default |
|---|---|---|
| `p2flux.paywall.recipient` | `P2FLUX_RECIPIENT` | none: required |
| `p2flux.paywall.price` | `P2FLUX_PAYWALL_PRICE` | `0.05` |
| `p2flux.paywall.prepaid` | `P2FLUX_PAYWALL_PREPAID` | `true` |
| `p2flux.paywall.on_unavailable` | `P2FLUX_PAYWALL_ON_UNAVAILABLE` | `refuse` |

The network comes from `P2FLUX_API_URL`: `https://api.p2flux.com` (the default) is Base with real
USDC, `https://api-test.p2flux.com` is Base Sepolia.

A paywalled route with no recipient throws a `RuntimeException` on its first request. It is never
served free by mistake.

## Routes

```php
Route::get('/report', ReportController::class)->middleware('p2flux.paywall');              // config price
Route::get('/data', DataController::class)->middleware('p2flux.paywall:0.20');              // this route's price
Route::get('/article/{id}', ArticleController::class)->middleware('p2flux.paywall:0.05,agents');
```

The first parameter is the route's price in USDC. The second, `agents`, charges AI agents and
programs only. With `agents`, name the price too: `p2flux.paywall:0.05,agents`.

## The cycle

1. A request without payment gets `402 Payment Required` as JSON. The `PAYMENT-REQUIRED` header
   (base64 JSON) says what to pay and to whom.
2. The agent signs a USDC payment and repeats the request with a `PAYMENT-SIGNATURE` header (or the
   legacy `X-PAYMENT`).
3. The middleware hands the header to P2Flux, which settles it on Base.
4. Your route runs. The response carries a `PAYMENT-RESPONSE` header with the settlement and
   `Cache-Control: no-store, private`.

The payment is settled **before** your route runs: one payment, one response. The same payment sent
again is refused. The money goes to your wallet in the settlement transaction; P2Flux takes its fee
on chain and never holds funds. The agent needs USDC on Base and no ETH.

The 402 names the resource as `application/json` when the request expects JSON, otherwise
`text/html`.

## Agents only

With `,agents`, people read free and agents pay. An agent is:

- a request carrying a payment;
- a request with no user agent;
- a request with a `Signature-Agent` header (Web Bot Auth), not verified, it only decides who pays;
- a user agent from the SDK's `Paywall::AGENT_SIGNATURES`: AI crawlers and assistants (GPTBot,
  ClaudeBot, PerplexityBot and others) and HTTP libraries (`curl/`, `python-requests` and others).

Search engines and link previews (Googlebot, bingbot, Slackbot and others) never pay. A user agent
is easy to fake: use `,agents` for content you are happy to show people, not for an API.

## When P2Flux cannot be reached

This is a decision, not a detail. Choose it:

- `P2FLUX_PAYWALL_ON_UNAVAILABLE=refuse` (default): the route answers `503` with `Retry-After: 60`.
  Nothing is served unpaid.
- `P2FLUX_PAYWALL_ON_UNAVAILABLE=free`: the route is served without payment. Content stays available
  while P2Flux is down, and nobody pays for it.

A recipient or price P2Flux refuses (a malformed wallet, a price below 0.01) is a configuration bug:
the SDK's `P2FluxException` is thrown, not a 402.

## Cache

The middleware uses your default cache store. The payment requirement is kept for the TTL P2Flux
gives it (an hour today), and a payment already used is refused for 10 minutes without asking
P2Flux. P2Flux refuses a used payment either way; the cache saves the round trip.

## Prepaid balance

With `P2FLUX_PAYWALL_PREPAID=true`, the 402 also offers x402 batch-settlement when P2Flux offers it.
The agent puts USDC aside once in the standard x402 escrow contract and then pays each request with a
signed voucher, with no transaction per request. You are paid out through an on-chain vault contract,
less 3%. The agent's unused balance stays its own; its request to take it back is answered for you.

`P2FLUX_PAYWALL_PREPAID=false` offers pay-per-request only.

## Fees

| | P2Flux fee |
|---|---|
| Pay-per-request | 1%, at least 0.003 USDC |
| Prepaid | 3% |

The fee is split out on chain. There is no free tier.

## Usage pricing

The middleware charges a fixed price. To charge what a request cost (tokens, rows, seconds), use the
SDK's `P2Flux\Paywall` and its `usage()` method in your controller. See the
[sdk-php paywall docs](https://github.com/P2Flux/sdk-php/blob/main/docs/paywall.md).

## From test to live

1. Run the whole cycle on `P2FLUX_API_URL=https://api-test.p2flux.com` first: a request without
   payment gets 402, an agent with test USDC pays, the repeat gets 200 with `PAYMENT-RESPONSE`. The
   [P2Flux MCP server](https://p2flux.com/docs/mcp.html) can play the agent.
2. Set `P2FLUX_RECIPIENT` to a mainnet wallet **you control**. Payments to it are final.
3. Set `P2FLUX_API_URL=https://api.p2flux.com`, or remove it: that is the default.
4. Decide `P2FLUX_PAYWALL_ON_UNAVAILABLE` on purpose.
5. Check each route's price: real USDC from here on.
6. `php artisan config:cache` after changing the environment.

More: [AI agent payments](https://p2flux.com/docs/agents.html) on p2flux.com.
