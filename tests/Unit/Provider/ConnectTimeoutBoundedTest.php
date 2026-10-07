<?php

declare(strict_types=1);

namespace Semitexa\Llm\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Semitexa\Core\Attribute\Config;

/**
 * What an LLM endpoint that has gone away is allowed to cost.
 *
 * The generation timeout has to stay generous — a local model thinking for a minute is
 * working, not broken. The connect timeout is the opposite kind of number: a host that
 * is simply gone never completes the handshake, so whatever sits there is paid in full
 * on every attempt, retries included, and it is paid on the worker BOOT path too via
 * the OS planner warm-up. Sharing one number between the two meant a stale base URL
 * could keep a Swoole worker dying and respawning indefinitely.
 *
 * So: no provider may hardcode the connect timeout of its completion path, and the
 * configured default must stay well under the generation budget. Scanned across the
 * whole provider directory rather than a hand-listed three, so a provider added later
 * inherits the rule instead of quietly opting out of it.
 */
final class ConnectTimeoutBoundedTest extends TestCase
{
    /** The bounded literal healthCheck() is allowed to keep — it is the cheap probe. */
    private const PROBE_CONNECT_TIMEOUT = 3;

    /**
     * The documented default. A provider may go lower — that is only stricter — but not higher:
     * the previous ceiling of 10 was exactly the hardcoded value this test exists to forbid, so
     * a new provider could reintroduce it and still pass.
     */
    private const MAX_CONFIGURED_DEFAULT = 5;

    /** @return array<string, array{0: string}> */
    public static function providerFiles(): array
    {
        $dir = \dirname(__DIR__, 3) . '/src/Application/Service';
        $files = glob($dir . '/*Provider.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'no provider sources found — the guard would pass vacuously');

        $cases = [];
        foreach ($files as $file) {
            if (in_array(basename($file, '.php'), self::OPENS_NO_CONNECTION, true)) {
                continue; // see the_providers_exempted_here_really_open_no_connection()
            }
            $cases[basename($file, '.php')] = [$file];
        }

        return $cases;
    }

    /**
     * Providers that answer without a network call, so there is no handshake to
     * bound. Named one by one rather than detected, so a new provider that does
     * open a connection cannot slip out of the rule by using a client this file
     * does not know about.
     */
    private const OPENS_NO_CONNECTION = ['ScriptedProvider'];

    #[Test]
    public function the_providers_exempted_here_really_open_no_connection(): void
    {
        foreach (self::OPENS_NO_CONNECTION as $name) {
            self::assertTrue(class_exists('Semitexa\\Llm\\Application\\Service\\' . $name), "{$name} is exempted but does not exist");
            $source = file_get_contents(\dirname(__DIR__, 3) . '/src/Application/Service/' . $name . '.php');
            self::assertIsString($source, "{$name} is exempted but its source is missing");
            self::assertStringContainsString('class ' . $name, $source, "{$name} is exempted but its source declares no such class");
            // Any stream or socket opener, whatever its argument: a URL held in a
            // variable opens a connection just as well as a literal one does.
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:curl_\w+|fsockopen|pfsockopen|stream_socket_client|socket_connect|fopen|file_get_contents|file|readfile|get_headers)\s*\(|Http\\\\|Client\b/',
                $source,
                "{$name} is exempted as network-free but can open a connection",
            );
        }
    }

    #[Test]
    #[DataProvider('providerFiles')]
    public function no_provider_hardcodes_the_connect_timeout_of_a_real_call(string $file): void
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        preg_match_all(
            '/CURLOPT_CONNECTTIMEOUT\s*=>\s*([^,\n]+)/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        self::assertNotEmpty($matches[1], basename($file) . ' sets no connect timeout at all');

        // By POSITION, not by text. The probe's own line is `CURLOPT_CONNECTTIMEOUT => 3`, so a
        // str_contains() check against the probe's body would happily bless an identical literal
        // written inside complete() — which is exactly the hole this guard is meant to close.
        [$probeStart, $probeEnd] = self::methodBounds($source, 'healthCheck');

        foreach ($matches[1] as [$raw, $offset]) {
            $value = trim($raw);
            $insideProbe = $probeStart !== null && $offset > $probeStart && $offset < $probeEnd;

            if ($value === (string) self::PROBE_CONNECT_TIMEOUT && $insideProbe) {
                continue; // the bounded healthCheck probe, and only there
            }

            self::assertSame(
                '$this->connectTimeout',
                $value,
                basename($file) . " hardcodes a connect timeout of {$value} outside healthCheck();"
                    . ' an unreachable host would cost that on every attempt, boot path included',
            );
        }
    }

    #[Test]
    #[DataProvider('providerFiles')]
    public function every_provider_configures_its_connect_timeout_separately_from_generation(string $file): void
    {
        $class = 'Semitexa\\Llm\\Application\\Service\\' . basename($file, '.php');
        self::assertTrue(class_exists($class), "{$class} does not exist");

        $reflection = new ReflectionClass($class);
        self::assertTrue(
            $reflection->hasProperty('connectTimeout'),
            basename($file) . ' has no $connectTimeout — the connect phase must be tunable on its own',
        );

        $connect = self::configDefault($reflection->getProperty('connectTimeout'));
        self::assertIsInt($connect, basename($file) . ' must declare an int default for $connectTimeout');
        self::assertGreaterThan(0, $connect);
        self::assertLessThanOrEqual(
            self::MAX_CONFIGURED_DEFAULT,
            $connect,
            basename($file) . " defaults the connect timeout to {$connect}s, which is back in boot-path territory",
        );

        $generation = self::configDefault($reflection->getProperty('timeout'));
        self::assertIsInt($generation);
        self::assertLessThan(
            $generation,
            $connect,
            basename($file) . ' must not spend its whole generation budget waiting for a handshake',
        );
    }

    /**
     * Where one method starts and ends in the file, so a literal can be judged by WHERE it sits.
     *
     * Brace-matched: the question is which region of this very string an offset falls in, and
     * reflection would answer in line numbers that then have to be mapped back to offsets.
     *
     * @return array{0: int|null, 1: int}
     */
    private static function methodBounds(string $source, string $method): array
    {
        $start = strpos($source, 'public function ' . $method . '(');
        if ($start === false) {
            return [null, 0];
        }

        $depth = 0;
        $seenBrace = false;
        for ($i = $start, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
                $seenBrace = true;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($seenBrace && $depth === 0) {
                    return [$start, $i];
                }
            }
        }

        return [$start, $len];
    }

    private static function configDefault(ReflectionProperty $property): mixed
    {
        foreach ($property->getAttributes(Config::class) as $attribute) {
            return $attribute->newInstance()->default;
        }

        self::fail($property->getName() . ' carries no #[Config] attribute');
    }
}
