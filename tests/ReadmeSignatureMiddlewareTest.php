<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use P2Flux\P2FluxClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The README's P2FluxSignature middleware, run as written: an API before the signing release sends no
 * p2flux-signature header (pass, the API verdict decides); a present but bad one is a 401.
 */
final class ReadmeSignatureMiddlewareTest extends TestCase
{
    private function middleware(): object
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $start = strpos($readme, '// app/Http/Middleware/P2FluxSignature.php');
        $end = strpos($readme, '// routes/web.php', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $namespace = 'ReadmeMiddleware' . bin2hex(random_bytes(4));
        eval("namespace {$namespace};\n" . substr($readme, (int) $start, (int) $end - (int) $start));
        $class = "{$namespace}\\P2FluxSignature";

        return new $class();
    }

    private function pass(object $middleware, Request $request): string
    {
        $this->app->instance(P2FluxClient::class, new P2FluxClient([
            'apiUrl' => 'https://readme-mw.p2flux.example',
            'transport' => new FakeTransport(['/v1/capabilities' => [200, ['webhook_signing_keys' => [['kid' => 'k1', 'alg' => 'Ed25519', 'public_key' => str_repeat('A', 43)]]]]]),
        ]));
        try {
            return (string) $middleware->handle($request, static fn (): string => 'next');
        } catch (HttpException $e) {
            return (string) $e->getStatusCode();
        }
    }

    #[Test]
    public function no_header_passes_on_a_present_bad_one_is_401(): void
    {
        $middleware = $this->middleware();
        $body = '{"id":"evt_1","type":"payment.paid"}';

        $this->assertSame('next', $this->pass($middleware, Request::create('/p2flux/notify/1', 'POST', [], [], [], [], $body)));
        $signed = Request::create('/p2flux/notify/1', 'POST', [], [], [], ['HTTP_P2FLUX_SIGNATURE' => 't=1800000000,kid=k1,v1=AAAA'], $body);
        $this->assertSame('401', $this->pass($middleware, $signed));
    }

    #[Test]
    public function the_readme_says_when_the_api_signs(): void
    {
        $this->assertStringContainsString('the API signs from release 2026-11', (string) file_get_contents(__DIR__ . '/../README.md'));
    }
}
