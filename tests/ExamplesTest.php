<?php

declare(strict_types=1);

namespace P2Flux\Laravel\Tests;

use P2Flux\P2FluxClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;

/**
 * The examples and the documentation are checked against the SDK they describe.
 *
 * A payments example that names a method which does not exist is worse than no example, and prose
 * nobody executes drifts. These are plain unit tests: no framework needed.
 */
final class ExamplesTest extends BaseTestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return list<array{string}> */
    public static function phpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/examples'));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = [$file->getPathname()];
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<array{string}> */
    public static function markdownFiles(): array
    {
        $files = [[self::ROOT . '/README.md']];
        foreach ((array) glob(self::ROOT . '/docs/*.md') as $doc) {
            $files[] = [(string) $doc];
        }
        foreach ((array) glob(self::ROOT . '/examples/*.md') as $doc) {
            $files[] = [(string) $doc];
        }

        return $files;
    }

    #[Test]
    #[DataProvider('phpFiles')]
    public function every_example_parses(string $file): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    #[Test]
    #[DataProvider('phpFiles')]
    public function every_example_is_labelled_as_application_code(string $file): void
    {
        $this->assertStringContainsString(
            'MERCHANT APPLICATION CODE',
            (string) file_get_contents($file),
            'an example must say plainly that it is not part of the package',
        );
    }

    #[Test]
    #[DataProvider('phpFiles')]
    #[DataProvider('markdownFiles')]
    public function every_documented_sdk_method_exists(string $file): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(P2FluxClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $text = (string) file_get_contents($file);

        preg_match_all('/\$(?:p2flux|this->p2flux)->([a-zA-Z]+)\(/', $text, $calls);
        preg_match_all('/P2Flux::([a-zA-Z]+)\(/', $text, $facadeCalls);

        $this->addToAssertionCount(1); // a file that calls nothing is fine, not risky

        foreach (array_unique(array_merge($calls[1], $facadeCalls[1])) as $method) {
            if (in_array($method, ['clearResolvedInstances', 'getFacadeRoot', 'shouldReceive'], true)) {
                continue;
            }
            $this->assertContains($method, $methods, basename($file) . " calls {$method}(), which the SDK does not have");
        }
    }

    #[Test]
    public function the_facade_documents_every_public_sdk_method(): void
    {
        $facade = (string) file_get_contents(self::ROOT . '/src/Facades/P2Flux.php');
        $methods = (new ReflectionClass(P2FluxClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            $this->assertStringContainsString(
                " {$method->getName()}(",
                $facade,
                "the facade has no @method line for {$method->getName()}()",
            );
        }

        // And nothing invented: every @method must be a real one.
        preg_match_all('/@method static [^\s]+ ([a-zA-Z]+)\(/', $facade, $documented);
        $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), $methods);
        foreach ($documented[1] as $name) {
            $this->assertContains($name, $names, "the facade documents {$name}(), which the SDK does not have");
        }
    }

    #[Test]
    #[DataProvider('phpFiles')]
    #[DataProvider('markdownFiles')]
    public function nothing_claims_a_feature_p2flux_does_not_have(string $file): void
    {
        /* Targeted phrases, not sentence parsing. There are no webhooks, there is no API key, and
         * the sponsored path is a network fee paid in USDC rather than a fee waived. */
        $text = strtolower((string) file_get_contents($file));

        foreach ([
            'webhook secret', 'webhook signature', 'verify the webhook', 'register a webhook',
            'webhook url', 'webhook endpoint', 'configure a webhook', 'webhook handler',
            'p2flux_api_key', 'your api key', "'api_key'", 'authorization: bearer', 'x-api-key',
            'gas-free', 'gas free', 'free transaction', 'no network fee',
            'composer require p2flux/p2flux-php', 'npm install github:p2flux',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, basename($file) . " contains \"{$forbidden}\"");
        }
    }

    #[Test]
    #[DataProvider('markdownFiles')]
    public function every_relative_link_resolves(string $file): void
    {
        preg_match_all('/\]\((?!https?:|#|mailto:)([^)#]+)(?:#[^)]*)?\)/', (string) file_get_contents($file), $links);

        $this->addToAssertionCount(1);

        foreach (array_unique($links[1]) as $link) {
            $this->assertFileExists(dirname($file) . '/' . $link, basename($file) . " links to {$link}");
        }
    }

    #[Test]
    public function the_readme_leads_with_the_install_command(): void
    {
        $readme = (string) file_get_contents(self::ROOT . '/README.md');

        $this->assertStringContainsString('composer require p2flux/laravel', $readme);
        $this->assertStringContainsString('no webhooks', strtolower($readme));
    }
}
