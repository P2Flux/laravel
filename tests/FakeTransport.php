<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

/**
 * The PHP SDK's documented transport stub: a callable that answers by path suffix and records what
 * the client actually sent.
 *
 * It is what keeps this suite offline. No test here touches a network, a chain or a wallet, and the
 * timeout it records is how the tests prove configuration reached the SDK rather than merely
 * reaching Laravel's config repository.
 */
final class FakeTransport
{
    /** @var list<array{url: string, payload: array<string, mixed>, timeout: int}> */
    public array $calls = [];

    /** @param array<string, array{0: int, 1: array<string, mixed>}> $responses keyed by path suffix */
    public function __construct(private array $responses = [])
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function __invoke(string $url, array $payload, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeout];

        foreach ($this->responses as $suffix => $response) {
            if (str_ends_with($url, $suffix)) {
                return $response;
            }
        }

        return [200, ['chain_id' => 8453, 'tokens' => []]];
    }
}
