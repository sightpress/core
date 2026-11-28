<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use SightPress\Core\Http\Session\DatabaseSessionStore;
use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Http\Session\RedisSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Http\Session\SessionStoreException;
use SightPress\Core\Http\Session\SessionStores;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\RedisServer;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(SessionStores::class)]
#[UsesClass(DatabaseSessionStore::class)]
#[UsesClass(FileSessionStore::class)]
#[UsesClass(RedisSessionStore::class)]
#[UsesClass(SessionStoreException::class)]
#[UsesClass(Services::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Crypto\SecretBox::class)]
final class SessionStoresTest extends TestCase
{
    public function testBuildsTheStoreTheTenantChose(): void
    {
        $container = new ArrayContainer([
            ClockInterface::class => new FixedClock(),
            Connection::class => DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
        ]);
        $site = SiteFixture::site();

        self::assertInstanceOf(DatabaseSessionStore::class, new SessionStores($this->config('database'))->forSite($site, $container));
        self::assertInstanceOf(FileSessionStore::class, new SessionStores($this->config('files'))->forSite($site, $container));
        self::assertNull(new SessionStores($this->config('files'))->customClass());

        $redis = new SessionStores($this->config('redis', new RedisServer('cache')))->forSite($site, $container);
        self::assertInstanceOf(RedisSessionStore::class, $redis);
        self::assertSame(RedisSessionStore::prefixFor($site->id) . hash('sha256', 'abc'), $redis->key('abc'));

        $own = self::createStub(SessionStore::class);
        $stores = new SessionStores($this->config($own::class));
        self::assertSame($own::class, $stores->customClass());
        self::assertSame($own, $stores->forSite($site, new ArrayContainer([ClockInterface::class => new FixedClock(), $own::class => $own])));
    }

    public function testRedisWithoutItsServerIsAClearError(): void
    {
        $this->expectException(SessionStoreException::class);
        $this->expectExceptionMessage('session.store is "redis", but SESSION_REDIS_DSN is not set.');

        new SessionStores($this->config('redis'))->forSite(SiteFixture::site(), new ArrayContainer([ClockInterface::class => new FixedClock()]));
    }

    /**
     * @param non-empty-string $store
     */
    private function config(string $store, ?RedisServer $redis = null): TenantConfig
    {
        return new TenantConfig(
            '/srv',
            \SightPress\Core\Tenant\Environment::Test,
            false,
            '/srv/config/sites.php',
            '/srv/sites',
            '/srv/var/cache',
            '/srv/var/log/test.log',
            \Monolog\Level::Notice,
            sessionPath: sys_get_temp_dir() . '/sp-sessions',
            sessionStore: $store,
            sessionRedis: $redis,
        );
    }
}
