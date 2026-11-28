<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use SightPress\Core\Http\Session\SessionStoreException;
use SightPress\Core\Tenant\RedisServer;
use SightPress\Core\Tenant\TenantConfigException;

#[CoversClass(RedisServer::class)]
#[UsesClass(SessionStoreException::class)]
final class RedisServerTest extends TestCase
{
    public function testReadsTheServerFromItsDsn(): void
    {
        $plain = RedisServer::fromDsn('redis://redis');
        self::assertSame(['redis', 6379, false, null, null, 0], [$plain->host, $plain->port, $plain->tls, $plain->user, $plain->password, $plain->database]);
        self::assertSame('redis:6379', $plain->address());

        $full = RedisServer::fromDsn('rediss://sessions:p%40ss@cache.example.org:6380/2');
        self::assertSame(['cache.example.org', 6380, true, 'sessions', 'p@ss', 2], [$full->host, $full->port, $full->tls, $full->user, $full->password, $full->database]);
        self::assertSame('cache.example.org:6380', $full->address(), 'the password is never shown');

        $password = RedisServer::fromDsn('redis://:secret@redis/');
        self::assertNull($password->user);
        self::assertSame('secret', $password->password);

        $socket = RedisServer::fromDsn('unix:///run/redis/redis.sock');
        self::assertSame(['/run/redis/redis.sock', 0], [$socket->host, $socket->port]);
        self::assertSame('/run/redis/redis.sock', $socket->address());
        self::assertTrue(RedisServer::available());
    }

    #[DataProvider('invalidDsns')]
    public function testRefusesWhatIsNotARedisDsn(string $dsn): void
    {
        $this->expectException(TenantConfigException::class);
        $this->expectExceptionMessage('SESSION_REDIS_DSN must look like redis://host:6379');

        RedisServer::fromDsn($dsn);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDsns(): iterable
    {
        yield 'another scheme' => ['http://redis'];
        yield 'no host' => ['redis://'];
        yield 'not a url' => ['redis:'];
        yield 'database name' => ['redis://redis/sessions'];
        yield 'relative socket' => ['unix://redis.sock'];
        yield 'empty socket' => ['unix://'];
    }

    public function testConnectsSignsInAndChoosesTheDatabase(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('connect')->with('tls://cache', 6380, 2.0)->willReturn(true);
        $redis->expects(self::once())->method('auth')->with(['sessions', 'secret'])->willReturn(true);
        $redis->expects(self::once())->method('select')->with(3)->willReturn(true);

        self::assertSame($redis, new RedisServer('cache', 6380, true, 'sessions', 'secret', 3)->connect($redis));

        $plain = $this->createMock(Redis::class);
        $plain->expects(self::once())->method('connect')->with('/run/redis.sock')->willReturn(true);
        $plain->expects(self::never())->method('select');
        $plain->expects(self::once())->method('auth')->with('secret')->willReturn(true);
        new RedisServer('/run/redis.sock', 0, password: 'secret')->connect($plain);
    }

    public function testSaysWhichServerCouldNotBeReachedWithoutItsPassword(): void
    {
        $refused = self::createStub(Redis::class);
        $refused->method('connect')->willReturn(false);
        try {
            new RedisServer('cache', password: 'top-secret')->connect($refused);
            self::fail('a refused connection is an error');
        } catch (SessionStoreException $exception) {
            self::assertStringContainsString('could not reach the Redis server at cache:6379, where sign-ins are kept (session.store = redis, SESSION_REDIS_DSN): the connection was refused.', $exception->getMessage());
            self::assertStringNotContainsString('top-secret', $exception->getMessage());
        }

        $wrong = self::createStub(Redis::class);
        $wrong->method('connect')->willReturn(true);
        $wrong->method('auth')->willThrowException(new RedisException('WRONGPASS invalid username-password pair'));
        $this->expectException(SessionStoreException::class);
        $this->expectExceptionMessage('WRONGPASS');
        new RedisServer('cache', password: 'top-secret')->connect($wrong);
    }

    public function testConnectsWithANewClientByDefault(): void
    {
        $this->expectException(SessionStoreException::class);
        $this->expectExceptionMessage('could not reach the Redis server at 127.0.0.1:1');

        new RedisServer('127.0.0.1', 1, timeout: 0.5)->connect();
    }
}
