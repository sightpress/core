<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Redis;
use SightPress\Core\Http\Session\SessionStoreCheck;
use SightPress\Core\Http\Session\SessionStoreException;
use SightPress\Core\Tenant\RedisServer;
use SightPress\Core\Tenant\TenantConfig;

#[CoversClass(SessionStoreCheck::class)]
#[UsesClass(SessionStoreException::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Crypto\SecretBox::class)]
final class SessionStoreCheckTest extends TestCase
{
    public function testSaysWhereSignInsAreKept(): void
    {
        self::assertSame('Sign-ins are kept in each site\'s database.', $this->check('database')->describe());
        self::assertSame('Sign-ins are kept in files in /srv/var/sessions, which works on one server only.', $this->check('files')->describe());
        self::assertSame('Sign-ins are kept in Redis at cache:6379.', $this->check('redis', new RedisServer('cache'))->describe());
        self::assertSame('Sign-ins are kept in Redis at an unknown address.', $this->check('redis')->describe());
        self::assertSame('Sign-ins are kept by Acme\Sessions.', $this->check('Acme\Sessions')->describe());
        self::assertNull($this->check('database')->problem());
        self::assertNull($this->check('redis')->problem());
    }

    public function testReportsARedisServerThatCantBeReached(): void
    {
        $answering = $this->createMock(Redis::class);
        $answering->method('connect')->willReturn(true);
        $answering->expects(self::once())->method('close')->willReturn(true);
        self::assertNull($this->check('redis', new RedisServer('cache'))->problem($answering));

        self::assertStringContainsString(
            'could not reach the Redis server at 127.0.0.1:1',
            (string) $this->check('redis', new RedisServer('127.0.0.1', 1, timeout: 0.5))->problem(),
        );
    }

    /**
     * @param non-empty-string $store
     */
    private function check(string $store, ?RedisServer $redis = null): SessionStoreCheck
    {
        return new SessionStoreCheck(new TenantConfig(
            '/srv',
            \SightPress\Core\Tenant\Environment::Test,
            false,
            '/srv/config/sites.php',
            '/srv/sites',
            '/srv/var/cache',
            '/srv/var/log/test.log',
            \Monolog\Level::Notice,
            sessionPath: '/srv/var/sessions',
            sessionStore: $store,
            sessionRedis: $redis,
        ));
    }
}
