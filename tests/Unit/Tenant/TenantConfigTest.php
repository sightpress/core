<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigException;

#[CoversClass(TenantConfig::class)]
#[UsesClass(Environment::class)]
final class TenantConfigTest extends TestCase
{
    public function testEmptyConfigGetsDefaults(): void
    {
        $config = TenantConfig::fromArray([], '/srv/app/');

        self::assertSame('/srv/app', $config->projectDir);
        self::assertSame(Environment::Production, $config->environment);
        self::assertFalse($config->debug);
        self::assertSame('/srv/app/config/sites.php', $config->registryPath);
        self::assertSame('/srv/app/sites', $config->sitesDir);
        self::assertSame('/srv/app/var/cache/production', $config->cacheDir);
        self::assertSame('/srv/app/var/log/production.log', $config->logPath);
        self::assertSame(Level::Notice, $config->logLevel);
        self::assertSame('en-GB', $config->adminLocale);
        self::assertSame('UTC', $config->defaultTimezone);
    }

    public function testItReadsEverySetting(): void
    {
        $config = TenantConfig::fromArray([
            'registry' => ['path' => '/etc/sites.php'],
            'sites_dir' => '/data/sites',
            'cache_dir' => '/tmp/cache',
            'log' => ['path' => 'php://stderr', 'level' => 'WARNING'],
            'admin' => ['default_locale' => 'pt-BR'],
            'timezone' => 'Europe/London',
        ], '/srv/app', ['APP_ENV' => 'staging', 'APP_DEBUG' => '1']);

        self::assertSame(Environment::Staging, $config->environment);
        self::assertTrue($config->debug);
        self::assertSame('/etc/sites.php', $config->registryPath);
        self::assertSame('/data/sites', $config->sitesDir);
        self::assertSame('/tmp/cache', $config->cacheDir);
        self::assertSame('php://stderr', $config->logPath);
        self::assertSame(Level::Warning, $config->logLevel);
        self::assertSame('pt-BR', $config->adminLocale);
        self::assertSame('Europe/London', $config->defaultTimezone);
    }

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function debugValues(): iterable
    {
        yield 'dev defaults to on' => ['dev', null, true];
        yield 'test defaults to off' => ['test', '', false];
        yield 'off in dev' => ['dev', '0', false];
        yield 'on in production' => ['production', 'true', true];
    }

    #[DataProvider('debugValues')]
    public function testDebugFollowsTheEnvironmentUnlessSet(string $env, ?string $debug, bool $expected): void
    {
        $environment = ['APP_ENV' => $env];
        if ($debug !== null) {
            $environment['APP_DEBUG'] = $debug;
        }

        self::assertSame($expected, TenantConfig::fromArray([], '/srv', $environment)->debug);
    }

    /**
     * @return iterable<string, array{array<mixed>, array<string, string>, string, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'project dir' => [[], [], '/', 'The project folder must not be empty.'];
        yield 'section' => [['registry' => 'x'], [], '/srv', 'Tenant setting "registry" must be an array.'];
        yield 'string' => [['sites_dir' => ' '], [], '/srv', 'Tenant setting "sites_dir" must be a non-empty string.'];
        yield 'debug' => [[], ['APP_DEBUG' => 'maybe'], '/srv', 'APP_DEBUG is not valid ("maybe"): use 1 or 0.'];
        yield 'log level' => [
            ['log' => ['level' => 'loud']],
            [],
            '/srv',
            'Tenant setting "log.level" is not valid ("loud")',
        ];
        yield 'locale' => [
            ['admin' => ['default_locale' => 'english']],
            [],
            '/srv',
            'Tenant setting "admin.default_locale" is not valid ("english")',
        ];
        yield 'timezone' => [
            ['timezone' => 'Mars/Olympus'],
            [],
            '/srv',
            'Tenant setting "timezone" is not valid ("Mars/Olympus")',
        ];
    }

    /**
     * @param array<mixed>          $config
     * @param array<string, string> $environment
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidSettingsAreNamed(array $config, array $environment, string $dir, string $message): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage($message);

        TenantConfig::fromArray($config, $dir, $environment);
    }

    /**
     * @return iterable<string, array{string, Level}>
     */
    public static function logLevels(): iterable
    {
        foreach (Level::cases() as $level) {
            yield $level->getName() => [strtolower($level->getName()), $level];
        }
    }

    #[DataProvider('logLevels')]
    public function testEveryLogLevelIsAccepted(string $name, Level $level): void
    {
        self::assertSame($level, TenantConfig::fromArray(['log' => ['level' => $name]], '/srv')->logLevel);
    }
}
