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

    /** No configured default may exceed this; past it the boot path is at risk again. */
    private const MAX_CONFIGURED_DEFAULT = 10;

    /** @return array<string, array{0: string}> */
    public static function providerFiles(): array
    {
        $dir = \dirname(__DIR__, 3) . '/src/Application/Service';
        $files = glob($dir . '/*Provider.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files, 'no provider sources found — the guard would pass vacuously');

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file, '.php')] = [$file];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('providerFiles')]
    public function no_provider_hardcodes_the_connect_timeout_of_a_real_call(string $file): void
    {
        $source = file_get_contents($file);
        self::assertIsString($source);

        preg_match_all('/CURLOPT_CONNECTTIMEOUT\s*=>\s*([^,\n]+)/', $source, $matches);
        self::assertNotEmpty($matches[1], basename($file) . ' sets no connect timeout at all');

        foreach ($matches[1] as $value) {
            $value = trim($value);
            if ($value === (string) self::PROBE_CONNECT_TIMEOUT) {
                continue; // the bounded healthCheck probe
            }

            self::assertSame(
                '$this->connectTimeout',
                $value,
                basename($file) . " hardcodes a connect timeout of {$value}; an unreachable host"
                    . ' would cost that on every attempt, on the boot path included',
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

    private static function configDefault(ReflectionProperty $property): mixed
    {
        foreach ($property->getAttributes(Config::class) as $attribute) {
            return $attribute->newInstance()->default;
        }

        self::fail($property->getName() . ' carries no #[Config] attribute');
    }
}
