<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use P2Flux\P2FluxClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What happens when the configuration is wrong.
 *
 * The rule these tests encode: a misconfigured application must fail loudly at start-up rather than
 * quietly doing something different from what the documentation promises. The specific danger is a
 * timeout of 0, which the SDK's curl transport hands to CURLOPT_TIMEOUT, where it means "wait
 * forever" - so `P2FLUX_TIMEOUT=abc` would have turned every request unbounded.
 */
final class BoundaryTest extends TestCase
{
    private function client(mixed $apiUrl, mixed $timeout): P2FluxClient
    {
        config(['p2flux.api_url' => $apiUrl, 'p2flux.timeout' => $timeout]);
        $this->app->forgetInstance(P2FluxClient::class);

        return $this->app->make(P2FluxClient::class);
    }

    /** @return array<string, array{mixed}> */
    public static function refusedTimeouts(): array
    {
        return [
            'zero means wait forever to curl' => [0],
            'negative' => [-5],
            'non-numeric' => ['abc'],
            'empty string' => [''],
            'blank string' => ['   '],
            'partly numeric' => ['12abc'],
            'float' => [1.5],
            'null' => [null],
            'array' => [[60]],
            'boolean' => [true],
        ];
    }

    #[Test]
    #[DataProvider('refusedTimeouts')]
    public function an_unusable_timeout_is_refused_by_name(mixed $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/P2FLUX_TIMEOUT/');

        $this->client('https://api.p2flux.com', $timeout);
    }

    /** @return array<string, array{mixed, int}> */
    public static function acceptedTimeouts(): array
    {
        return [
            'integer' => [30, 30],
            'numeric string, as env always gives' => ['30', 30],
            'one second' => [1, 1],
            'very large' => [86400, 86400],
        ];
    }

    #[Test]
    #[DataProvider('acceptedTimeouts')]
    public function a_usable_timeout_reaches_the_sdk(mixed $timeout, int $expected): void
    {
        $client = $this->client('https://api.p2flux.com', $timeout);

        $this->assertSame($expected, $this->clientProperty($client, 'timeout'));
    }

    /** @return array<string, array{mixed}> */
    public static function refusedUrls(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'null' => [null],
            'array' => [['https://api.p2flux.com']],
        ];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function an_empty_api_url_is_refused_by_name(mixed $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/P2FLUX_API_URL/');

        $this->client($url, 60);
    }

    #[Test]
    public function a_missing_config_key_falls_back_to_the_package_default(): void
    {
        // An application that cached its config before this package existed has neither key.
        config(['p2flux' => []]);
        $this->app->forgetInstance(P2FluxClient::class);

        $client = $this->app->make(P2FluxClient::class);

        $this->assertSame('https://api.p2flux.com', $this->clientProperty($client, 'apiUrl'));
        $this->assertSame(60, $this->clientProperty($client, 'timeout'));
    }

    #[Test]
    public function surrounding_whitespace_in_the_url_is_trimmed(): void
    {
        $client = $this->client("  https://api-test.p2flux.com\n", 60);

        $this->assertSame('https://api-test.p2flux.com', $this->clientProperty($client, 'apiUrl'));
    }

    #[Test]
    public function a_trailing_slash_is_normalised_by_the_sdk(): void
    {
        $client = $this->client('https://api-test.p2flux.com/', 60);

        $this->assertSame('https://api-test.p2flux.com', $this->clientProperty($client, 'apiUrl'));
    }

    #[Test]
    public function a_malformed_but_present_url_is_the_operator_s_choice_and_fails_at_call_time(): void
    {
        /* P2FLUX_API_URL is administrator-controlled, so pointing it at a private host is a
         * deployment decision rather than a vulnerability, and the package does not second-guess it.
         * What must not happen is a silent success: an unreachable host is a retryable transport
         * failure, never a payment verdict. */
        $client = $this->client('not-a-url', 2);

        $result = $client->charge('p2s2.k1.test.mac');

        $this->assertSame('NETWORK_ERROR', $result->status);
        $this->assertSame('RETRY_LATER', $result->action);
        $this->assertTrue($result->retryable);
    }

    #[Test]
    public function artisan_about_reports_invalid_configuration_instead_of_throwing(): void
    {
        // `about` is where an operator looks when something is wrong; it must survive the wrong thing.
        config(['p2flux.api_url' => '', 'p2flux.timeout' => 'abc']);

        $this->assertSame(0, Artisan::call('about', ['--only' => 'p2flux']));

        $output = Artisan::output();
        $this->assertStringContainsString('invalid', $output);
        $this->assertStringContainsString("'abc'", $output);
    }
}
