<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use P2Flux\P2FluxClient;
use P2Flux\Paywall as SdkPaywall;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charge AI agents for a route, in USDC, over x402.
 *
 *   Route::get('/report', ReportController::class)->middleware('p2flux.paywall');          // config price
 *   Route::get('/data', DataController::class)->middleware('p2flux.paywall:0.20');          // this route's price
 *   Route::get('/article/{id}', ...)->middleware('p2flux.paywall:0.05,agents');             // browsers pass free
 *
 * The payment is settled through P2Flux BEFORE the route runs: one payment, one response. Money goes
 * to `p2flux.paywall.recipient`; the fee is taken on chain. This is the PHP SDK's `P2Flux\Paywall`
 * with Laravel's request, response and cache - nothing else.
 */
final class Paywall
{
    public function __construct(
        private readonly P2FluxClient $client,
        private readonly Config $config,
        private readonly Cache $cache,
    ) {
    }

    /**
     * @param string|null $price USDC for this route; the configured price when omitted
     * @param string|null $who   'agents': only AI agents and programs pay. Anything else: everyone pays.
     */
    public function handle(Request $request, Closure $next, ?string $price = null, ?string $who = null): Response
    {
        /** @var array<string, mixed> $settings */
        $settings = (array) $this->config->get('p2flux.paywall', []);
        $recipient = (string) ($settings['recipient'] ?? '');
        if ($recipient === '') {
            // A paywall with nobody to pay is a deployment mistake; serving the route free would hide it.
            throw new \RuntimeException('p2flux.paywall.recipient is not set (P2FLUX_RECIPIENT)');
        }

        $paywall = new SdkPaywall($this->client, [
            'recipient' => $recipient,
            'price' => $price ?? (string) ($settings['price'] ?? '0.05'),
            'agentsOnly' => $who === 'agents',
            'prepaid' => (bool) ($settings['prepaid'] ?? true),
            'onUnavailable' => ($settings['on_unavailable'] ?? 'refuse') === 'free' ? 'free' : 'refuse',
            'cacheGet' => fn (string $key): mixed => $this->cache->get($key),
            'cacheSet' => function (string $key, mixed $value, int $ttl): void {
                $this->cache->put($key, $value, $ttl);
            },
        ]);

        $result = $paywall->guard(
            $request->headers->get('PAYMENT-SIGNATURE') ?? $request->headers->get('X-PAYMENT'),
            $request->fullUrl(),
            $request->userAgent(),
            ['mimeType' => $request->expectsJson() ? 'application/json' : 'text/html'],
        );

        if ($result['allow'] === false) {
            return new JsonResponse($result['body'], $result['status'], $result['headers']);
        }

        /** @var Response $response */
        $response = $next($request);
        foreach ($result['headers'] as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
