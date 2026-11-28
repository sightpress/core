<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use Monolog\Level;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\IniSize;
use SightPress\Core\Tenant\Environment;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tenant\TenantConfigException;

#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantAssistant::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantPolicies::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantSms::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TwoStepPolicy::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantThemes::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\SightPress\Core\Tenant\TenantRetention::class)]
#[CoversClass(TenantConfig::class)]
#[UsesClass(\SightPress\Core\Tenant\RedisServer::class)]
#[UsesClass(Environment::class)]
#[UsesClass(LocaleCode::class)]
#[UsesClass(IniSize::class)]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteMediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\TenantMediaLimits::class)]
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
        self::assertSame('/srv/app/var/sessions', $config->sessionPath);
        self::assertSame(7200, $config->sessionIdleTimeout);
        self::assertNull($config->secret);
        self::assertSame('null://null', $config->mailerDsn);
        self::assertSame('no-reply@localhost', $config->mailFrom);
        self::assertSame('SightPress', $config->mailFromName);
    }

    public function testReadsHowToSendEmail(): void
    {
        $config = TenantConfig::fromArray(
            ['mail' => ['from' => 'hello@example.org', 'from_name' => 'Example Sites']],
            '/srv/app',
            ['MAILER_DSN' => ' smtp://mail.example.org:587 '],
        );

        self::assertSame('smtp://mail.example.org:587', $config->mailerDsn);
        self::assertSame('hello@example.org', $config->mailFrom);
        self::assertSame('Example Sites', $config->mailFromName);
    }

    public function testReadsTheSecretFromTheEnvironment(): void
    {
        $secret = str_repeat('s', 32);

        self::assertSame($secret, TenantConfig::fromArray([], '/srv/app', ['APP_SECRET' => $secret])->secret);
        self::assertNull(TenantConfig::fromArray([], '/srv/app', ['APP_SECRET' => ' '])->secret);
    }

    public function testRefusesAShortSecret(): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('APP_SECRET is too short: use at least 32 random characters');

        TenantConfig::fromArray([], '/srv/app', ['APP_SECRET' => 'short']);
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
            'session' => ['path' => '/var/lib/sessions', 'idle_timeout' => 900],
            'themes' => ['allowed' => ['default', 'acme-harbour'], 'default' => 'acme-harbour'],
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
        self::assertSame('/var/lib/sessions', $config->sessionPath);
        self::assertSame(900, $config->sessionIdleTimeout);
        self::assertSame(['default', 'acme-harbour'], $config->themes->allowed);
        self::assertSame('acme-harbour', $config->themes->default);
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
        $body = 'Tenant setting "uploads.proxy_body_limit" must be the web server\'s request body limit';
        yield 'body limit in words' => [['uploads' => ['proxy_body_limit' => 'big']], [], '/srv', $body];
        yield 'body limit zero' => [['uploads' => ['proxy_body_limit' => 0]], [], '/srv', $body];
        yield 'body limit 0M' => [['uploads' => ['proxy_body_limit' => '0M']], [], '/srv', $body];
        yield 'body limit list' => [['uploads' => ['proxy_body_limit' => [1]]], [], '/srv', $body];
        $public = 'Tenant setting "uploads.temp_path" must not be inside the public folder.';
        yield 'temp path is public' => [['uploads' => ['temp_path' => 'public']], [], '/srv', $public];
        yield 'temp path under public' => [['uploads' => ['temp_path' => '/srv/public/parts']], [], '/srv', $public];
        yield 'temp path climbs into public' => [['uploads' => ['temp_path' => 'var/../public/x']], [], '/srv', $public];
        $chunked = 'Tenant setting "uploads.max_chunked_bytes" must be a size';
        yield 'chunked limit in words' => [['uploads' => ['max_chunked_bytes' => 'big']], [], '/srv', $chunked];
        yield 'chunked limit zero' => [['uploads' => ['max_chunked_bytes' => 0]], [], '/srv', $chunked];
        yield 'uploads not a list' => [['uploads' => 'x'], [], '/srv', 'Tenant setting "uploads" must be an array.'];
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
        yield 'idle timeout' => [
            ['session' => ['idle_timeout' => 30]],
            [],
            '/srv',
            'Tenant setting "session.idle_timeout" must be a whole number of seconds, at least 60.',
        ];
        yield 'session store' => [
            ['session' => ['store' => 'memcached']],
            [],
            '/srv',
            'Tenant setting "session.store" must be "database", "redis", "files" or a class implementing SightPress\\Core\\Http\\Session\\SessionStore ("memcached" is none of these).',
        ];
        yield 'session store class' => [
            ['session' => ['store' => \stdClass::class]],
            [],
            '/srv',
            '("stdClass" is none of these)',
        ];
        yield 'redis without its server' => [
            ['session' => ['store' => 'redis']],
            [],
            '/srv',
            'Tenant setting "session.store" is "redis", but SESSION_REDIS_DSN is not set.',
        ];
        yield 'redis server' => [
            ['session' => ['store' => 'redis']],
            ['SESSION_REDIS_DSN' => 'http://redis'],
            '/srv',
            'SESSION_REDIS_DSN must look like redis://host:6379',
        ];
        yield 'sender address' => [
            ['mail' => ['from' => 'not an address']],
            [],
            '/srv',
            'Tenant setting "mail.from" is not an email address ("not an address").',
        ];
        yield 'timezone' => [
            ['timezone' => 'Mars/Olympus'],
            [],
            '/srv',
            'Tenant setting "timezone" is not valid ("Mars/Olympus")',
        ];
        yield 'module switch' => [
            ['modules' => ['locked' => ['acme.events' => 'on']]],
            [],
            '/srv',
            'Tenant setting "modules.locked" must list module ids, each set to true (on) or false (off)',
        ];
        yield 'module list' => [
            ['modules' => ['defaults' => [true]]],
            [],
            '/srv',
            'Tenant setting "modules.defaults" must list module ids',
        ];
    }

    public function testSessionsAreKeptInTheSitesDatabaseUnlessAnotherStoreIsChosen(): void
    {
        $default = TenantConfig::fromArray([], '/srv/app');
        self::assertSame(TenantConfig::SESSION_DATABASE, $default->sessionStore);
        self::assertNull($default->sessionRedis);

        self::assertSame('files', TenantConfig::fromArray(['session' => ['store' => 'files']], '/srv/app')->sessionStore);
        self::assertSame(
            \SightPress\Core\Http\Session\FileSessionStore::class,
            TenantConfig::fromArray(['session' => ['store' => \SightPress\Core\Http\Session\FileSessionStore::class]], '/srv/app')->sessionStore,
        );

        $redis = TenantConfig::fromArray(
            ['session' => ['store' => 'redis']],
            '/srv/app',
            ['SESSION_REDIS_DSN' => 'redis://:secret@cache:6380/2'],
            true,
        );
        self::assertSame(TenantConfig::SESSION_REDIS, $redis->sessionStore);
        self::assertSame('cache:6380', $redis->sessionRedis?->address());
        self::assertSame(2, $redis->sessionRedis->database);
        self::assertNull(
            TenantConfig::fromArray([], '/srv/app', ['SESSION_REDIS_DSN' => 'redis://cache'])->sessionRedis,
            'the Redis server is only read for the redis store',
        );
    }

    public function testRedisSessionsNeedPhpsRedisExtension(): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('PHP\'s redis extension is not installed. Install it (pecl install redis) or choose "database".');

        TenantConfig::fromArray(['session' => ['store' => 'redis']], '/srv/app', ['SESSION_REDIS_DSN' => 'redis://cache'], false);
    }

    public function testReadsTheModuleDefaultsAndLocks(): void
    {
        $config = TenantConfig::fromArray(['modules' => ['defaults' => ['acme.events' => false], 'locked' => ['analytics' => true]]], '/srv');

        self::assertSame([['acme.events' => false], ['analytics' => true]], [$config->moduleDefaults, $config->moduleLocks]);
        self::assertSame([[], []], [TenantConfig::fromArray([], '/srv')->moduleDefaults, TenantConfig::fromArray([], '/srv')->moduleLocks]);
    }

    public function testReadsTheWebServersBodyLimit(): void
    {
        self::assertNull(TenantConfig::fromArray([], '/srv')->uploadProxyBodyLimit);
        self::assertNull(TenantConfig::fromArray(['uploads' => ['proxy_body_limit' => '']], '/srv')->uploadProxyBodyLimit);
        self::assertSame(65 * 1024 * 1024, TenantConfig::fromArray(['uploads' => ['proxy_body_limit' => '65M']], '/srv')->uploadProxyBodyLimit);
        self::assertSame(1024 ** 3, TenantConfig::fromArray(['uploads' => ['proxy_body_limit' => ' 1g ']], '/srv')->uploadProxyBodyLimit);
        self::assertSame(1000, TenantConfig::fromArray(['uploads' => ['proxy_body_limit' => 1000]], '/srv')->uploadProxyBodyLimit);
    }

    public function testReadsWhereLargeUploadsWaitAndHowLargeTheyCanBe(): void
    {
        self::assertSame('/srv/var/uploads', TenantConfig::fromArray([], '/srv')->uploadTempPath);
        self::assertSame('/data/parts', TenantConfig::fromArray(['uploads' => ['temp_path' => '/data/./parts/']], '/srv')->uploadTempPath);
        self::assertSame('/srv/tmp', TenantConfig::fromArray(['uploads' => ['temp_path' => 'var/../tmp']], '/srv')->uploadTempPath);
        self::assertSame(5 * 1024 ** 3, TenantConfig::fromArray([], '/srv')->maxChunkedBytes);
        self::assertSame(1024 ** 3, TenantConfig::fromArray(['uploads' => ['max_chunked_bytes' => '1G']], '/srv')->maxChunkedBytes);
        self::assertSame(1000, TenantConfig::fromArray(['uploads' => ['max_chunked_bytes' => 1000]], '/srv')->maxChunkedBytes);
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
