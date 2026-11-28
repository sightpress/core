<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use Closure;
use JsonException;
use Redis;
use RedisException;

/**
 * Keeps sessions in Redis (`session.store` = `redis`, server in `SESSION_REDIS_DSN`), so every web server shares
 * them. Each key is the site's prefix and a SHA-256 hash of the session id, so a raw id never appears in Redis and
 * sites sharing a server never see each other's sessions. Redis ends a session itself: each key lives for the idle
 * timeout, and every request restarts it.
 *
 * The connection is made on first use. A server that can't be reached is a {@see SessionStoreException} that says
 * which server and what to check.
 */
final class RedisSessionStore implements SessionStore
{
    private ?Redis $redis = null;

    /**
     * @param Closure(): Redis $connect     opens the connection ({@see \SightPress\Core\Tenant\RedisServer::connect()})
     * @param non-empty-string $prefix      starts every key of the site, for example `sightpress:session:<site id>:`
     * @param positive-int     $idleTimeout seconds without a request before a session ends
     */
    public function __construct(
        private readonly Closure $connect,
        private readonly string $prefix,
        private readonly int $idleTimeout,
    ) {
    }

    /**
     * The key prefix of a site's sessions.
     *
     * @return non-empty-string
     */
    public static function prefixFor(string $siteId): string
    {
        return 'sightpress:session:' . $siteId . ':';
    }

    public function read(string $id): ?array
    {
        $json = $this->call(static fn (Redis $redis, string $key): mixed => $redis->get($key), $id);
        if (!is_string($json)) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function write(string $id, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $ttl = $this->idleTimeout;
        $this->call(static fn (Redis $redis, string $key): mixed => $redis->set($key, $json, ['ex' => $ttl]), $id);
    }

    public function touch(string $id): void
    {
        // EXPIRE does nothing to a key that is gone, so an ended session stays ended.
        $ttl = $this->idleTimeout;
        $this->call(static fn (Redis $redis, string $key): mixed => $redis->expire($key, $ttl), $id);
    }

    public function delete(string $id): void
    {
        $this->call(static fn (Redis $redis, string $key): mixed => $redis->del($key), $id);
    }

    /**
     * The Redis key of a session id.
     */
    public function key(string $id): string
    {
        return $this->prefix . hash('sha256', $id);
    }

    /**
     * @param Closure(Redis, string): mixed $command
     */
    private function call(Closure $command, string $id): mixed
    {
        $this->redis ??= ($this->connect)();
        try {
            return $command($this->redis, $this->key($id));
        } catch (RedisException $exception) {
            $this->redis = null;
            throw new SessionStoreException(sprintf('The Redis server where sign-ins are kept stopped answering: %s.', $exception->getMessage()), 0, $exception);
        }
    }
}
