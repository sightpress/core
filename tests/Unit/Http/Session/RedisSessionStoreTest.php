<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use SightPress\Core\Http\Session\RedisSessionStore;
use SightPress\Core\Http\Session\SessionStoreException;

#[CoversClass(RedisSessionStore::class)]
#[UsesClass(SessionStoreException::class)]
#[UsesClass(\SightPress\Core\Http\Session\Session::class)]
final class RedisSessionStoreTest extends TestCase
{
    public function testKeysAreTheSitesPrefixAndAHashOfTheId(): void
    {
        self::assertSame('sightpress:session:site-1:', RedisSessionStore::prefixFor('site-1'));
        self::assertSame('sightpress:session:site-1:' . hash('sha256', 'abc'), $this->store(self::createStub(Redis::class))->key('abc'));
    }

    public function testWritesWithTheIdleTimeoutAndReads(): void
    {
        $key = 'p:' . hash('sha256', 'abc');
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::once())->method('set')->with($key, '{"user_id":7}', ['ex' => 900])->willReturn(true);
        $redis->expects(self::once())->method('expire')->with($key, 900)->willReturn(true);
        $redis->expects(self::once())->method('del')->with($key)->willReturn(1);
        $redis->method('get')->willReturnOnConsecutiveCalls('{"user_id":7}', false, '{oops', '42');
        $connections = 0;
        $store = new RedisSessionStore(static function () use ($redis, &$connections): Redis {
            ++$connections;

            return $redis;
        }, 'p:', 900);

        $store->write('abc', ['user_id' => 7]);
        self::assertSame(['user_id' => 7], $store->read('abc'));
        self::assertNull($store->read('abc'), 'gone');
        self::assertNull($store->read('abc'), 'damaged');
        self::assertNull($store->read('abc'), 'not a list');
        $store->touch('abc');
        $store->delete('abc');
        self::assertSame(1, $connections, 'one connection per request');
    }

    public function testListsASignedInPersonsSessionsSoTheyCanBeFoundAndEnded(): void
    {
        $session = 'p:' . hash('sha256', 'abc');
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::exactly(2))->method('set')->willReturn(true);
        $redis->expects(self::once())->method('sAdd')->with('p:user:7', $session)->willReturn(1);
        $redis->expects(self::exactly(3))->method('sMembers')->with('p:user:7')->willReturnOnConsecutiveCalls(['p:new', 'p:gone', 'p:old'], ['p:new', 'p:old'], false);
        $redis->method('ttl')->willReturnMap([['p:new', 900], ['p:gone', -2], ['p:old', 300]]);
        $redis->expects(self::once())->method('srem')->with('p:user:7', 'p:gone')->willReturn(1);
        $redis->expects(self::exactly(3))->method('del')->willReturnCallback(static fn (string|array $keys): int|false => $keys === ['p:new', 'p:old'] ? 2 : false);
        $store = new RedisSessionStore(static fn (): Redis => $redis, 'p:', 900, new \SightPress\Core\Tests\Support\FixedClock(new \DateTimeImmutable('2026-11-27 12:00:00 UTC')));

        $store->write('abc', [\SightPress\Core\Http\Session\Session::USER_ID => 7]);
        $store->write('visitor', ['csrf' => []]);
        self::assertSame([
            ['last_seen_at' => '2026-11-27T11:50:00+00:00', 'expires_at' => '2026-11-27T12:05:00+00:00'],
            ['last_seen_at' => '2026-11-27T12:00:00+00:00', 'expires_at' => '2026-11-27T12:15:00+00:00'],
        ], $store->forPerson(7), 'oldest first; keys that ended are dropped from the list');
        self::assertSame(2, $store->forgetPerson(7));
        self::assertSame(0, $store->forgetPerson(7), 'nothing listed any more');
    }

    public function testAServerThatStopsAnsweringIsAClearErrorAndTheNextCallReconnects(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects(self::exactly(2))->method('get')->willReturnCallback(static function (): never {
            throw new RedisException('Connection lost');
        });
        $connections = 0;
        $store = new RedisSessionStore(static function () use ($redis, &$connections): Redis {
            ++$connections;

            return $redis;
        }, 'p:', 900);

        try {
            $store->read('abc');
            self::fail('a lost connection is an error');
        } catch (SessionStoreException $exception) {
            self::assertSame('The Redis server where sign-ins are kept stopped answering: Connection lost.', $exception->getMessage());
        }
        $this->expectException(SessionStoreException::class);
        try {
            $store->read('abc');
        } finally {
            self::assertSame(2, $connections);
        }
    }

    private function store(Redis $redis): RedisSessionStore
    {
        return new RedisSessionStore(static fn (): Redis => $redis, 'sightpress:session:site-1:', 900);
    }
}
