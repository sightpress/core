<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http\Session;

use Redis;
use SightPress\Core\Http\Session\RedisSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Tenant\RedisServer;

/**
 * Against a real Redis server, named by SIGHTPRESS_TEST_REDIS_DSN (CI starts one); skipped without it. Time passes
 * for real here, so the expiry test takes a few seconds.
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class RedisSessionStoreContractTest extends SessionStoreContract
{
    public const string ENVIRONMENT = 'SIGHTPRESS_TEST_REDIS_DSN';

    private RedisServer $server;

    private Redis $redis;

    /** @var non-empty-string */
    private string $prefix;

    protected function setUp(): void
    {
        $dsn = getenv(self::ENVIRONMENT);
        if (!is_string($dsn) || $dsn === '' || !RedisServer::available()) {
            self::markTestSkipped('Set ' . self::ENVIRONMENT . ' to a Redis server (and install the redis extension) to run the Redis session tests.');
        }
        $this->server = RedisServer::fromDsn($dsn);
        $this->redis = $this->server->connect();
        $this->prefix = RedisSessionStore::prefixFor('test-' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys($this->prefix . '*');
            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->close();
        }
    }

    protected function store(int $idleTimeout = 3600): SessionStore
    {
        return new RedisSessionStore($this->server->connect(...), $this->prefix, $idleTimeout);
    }

    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }

    protected function stored(): string
    {
        $stored = '';
        $keys = $this->redis->keys($this->prefix . '*');
        foreach (is_array($keys) ? $keys : [] as $key) {
            $value = $this->redis->get($key);
            $stored .= $key . "\n" . (is_string($value) ? $value : '') . "\n";
        }

        return $stored;
    }

    public function testEachSiteHasItsOwnKeysWhichRedisEnds(): void
    {
        $store = $this->store(600);
        $store->write('session-id', ['user_id' => 7]);
        $other = new RedisSessionStore($this->server->connect(...), RedisSessionStore::prefixFor('another-site'), 600);

        self::assertNull($other->read('session-id'), 'another site sharing the server never sees the session');
        $ttl = $this->redis->ttl($this->prefix . hash('sha256', 'session-id'));
        self::assertGreaterThan(590, $ttl);
        self::assertLessThanOrEqual(600, $ttl);
    }
}
