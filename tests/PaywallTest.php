<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use P2Flux\P2FluxClient;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `p2flux.paywall` middleware: the route runs only after P2Flux settled the agent's payment for
 * the configured wallet and price.
 */
final class PaywallTest extends TestCase
{
    private const WALLET = '0xb4e43f3fBa5Add75395adAD366627E7d74141Fa9';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    public int $ran = 0;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('p2flux.paywall.recipient', self::WALLET);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/report', function () {
            $this->ran++;

            return response('PAID CONTENT');
        })->middleware('p2flux.paywall');
        $router->get('/premium', fn () => 'PREMIUM')->middleware('p2flux.paywall:0.20');
        $router->get('/article', fn () => 'ARTICLE')->middleware('p2flux.paywall:0.05,agents');
    }

    private static function b64(array $v): string
    {
        return base64_encode((string) json_encode($v));
    }

    /** @return array<string, mixed> */
    private static function decode(?string $h): array
    {
        return (array) json_decode((string) base64_decode((string) $h), true);
    }

    /** A P2Flux that pays each payment once. */
    private function api(): FakeTransport
    {
        $used = [];
        $fake = new FakeTransport();
        $client = new P2FluxClient([
            'apiUrl' => 'https://api-test.p2flux.com',
            'transport' => function (string $url, array $payload, int $timeout) use (&$used, $fake): array {
                $fake($url, $payload, $timeout);
                if (str_ends_with($url, '/challenge')) {
                    return [200, ['x402Version' => 2, 'ttl' => 3600, 'accepts' => [
                        ['scheme' => 'exact', 'amount' => (string) (int) round(((float) $payload['price']) * 1e6), 'payTo' => '0xvault'],
                        ['scheme' => 'batch-settlement', 'amount' => (string) (int) round(((float) $payload['price']) * 1e6), 'payTo' => '0xbatch'],
                    ]]];
                }
                $id = (string) (self::decode($payload['payment'])['id'] ?? '');
                if (isset($used[$id])) {
                    return [200, ['paid' => false, 'reason' => 'invalid_transaction_state']];
                }
                $used[$id] = true;

                return [200, ['paid' => true, 'transaction' => '0x' . str_repeat('ab', 32), 'payment_response' => self::b64(['success' => true])]];
            },
        ]);
        $this->app->instance(P2FluxClient::class, $client);

        return $fake;
    }

    #[Test]
    public function without_payment_the_route_does_not_run_and_the_answer_is_402_with_the_price(): void
    {
        $api = $this->api();
        $response = $this->get('/report');

        $response->assertStatus(402);
        $this->assertSame(0, $this->ran);
        $required = self::decode($response->headers->get('PAYMENT-REQUIRED'));
        $this->assertSame(2, $required['x402Version']);
        $this->assertStringEndsWith('/report', $required['resource']['url']);
        $this->assertSame('50000', $required['accepts'][0]['amount']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(['recipient' => self::WALLET, 'price' => '0.05'], $api->calls[0]['payload']);
    }

    #[Test]
    public function a_payment_is_settled_with_the_configured_wallet_and_price_then_the_route_runs_once(): void
    {
        $api = $this->api();
        $header = self::b64(['id' => 'p1']);

        $paid = $this->get('/report', ['PAYMENT-SIGNATURE' => $header]);
        $paid->assertOk()->assertSee('PAID CONTENT');
        $this->assertTrue(self::decode($paid->headers->get('PAYMENT-RESPONSE'))['success']);
        $redeem = $api->calls[array_key_last($api->calls)]['payload'];
        $this->assertSame(self::WALLET, $redeem['recipient']);
        $this->assertSame('0.05', $redeem['price']);
        $this->assertSame($header, $redeem['payment']);

        // The same payment again: refused, the route does not run a second time.
        $again = $this->get('/report', ['PAYMENT-SIGNATURE' => $header]);
        $again->assertStatus(402);
        $this->assertSame('invalid_transaction_state', self::decode($again->headers->get('PAYMENT-REQUIRED'))['error']);
        $this->assertSame(1, $this->ran);
    }

    #[Test]
    public function a_route_can_set_its_own_price(): void
    {
        $this->api();
        $required = self::decode($this->get('/premium')->headers->get('PAYMENT-REQUIRED'));
        $this->assertSame('200000', $required['accepts'][0]['amount']);
    }

    #[Test]
    public function agents_mode_lets_browsers_through_and_charges_agents(): void
    {
        $api = $this->api();
        $this->get('/article', ['User-Agent' => self::CHROME])->assertOk()->assertSee('ARTICLE');
        $this->assertSame([], $api->calls);
        $this->get('/article', ['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.2)'])->assertStatus(402);
    }

    #[Test]
    public function the_requirement_is_cached_between_requests(): void
    {
        $api = $this->api();
        $this->get('/report');
        $this->get('/report');
        $this->assertCount(1, $api->calls);
    }

    #[Test]
    public function prepaid_can_be_switched_off_in_config(): void
    {
        $this->api();
        config(['p2flux.paywall.prepaid' => false]);
        $required = self::decode($this->get('/report')->headers->get('PAYMENT-REQUIRED'));
        $this->assertSame(['exact'], array_column($required['accepts'], 'scheme'));
    }

    #[Test]
    public function a_paywall_with_no_recipient_is_an_error_not_a_free_route(): void
    {
        $this->api();
        config(['p2flux.paywall.recipient' => null]);
        $this->get('/report')->assertStatus(500);
        $this->assertSame(0, $this->ran);
    }
}
