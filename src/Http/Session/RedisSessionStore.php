<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use Closure;
use DateTimeImmutable;
use JsonException;
use Psr\Clock\ClockInterface;
use Redis;
use RedisException;

/**
 * Keeps sessions in Redis (`session.store` = `redis`, server in `SESSION_REDIS_DSN`), so every web server shares
 * them. Each key is the site's prefix and a SHA-256 hash of the session id, so a raw id never appears in Redis and
 * sites sharing a server never see each other's sessions. Redis ends a session itself: each key lives for the idle
 * timeout, and every request restarts it. A signed-in person's session keys are also listed in a set
 * (`<prefix>user:<id>`), so they can be found and ended ({@see PersonSessions}).
 *
 * The connection is made on first use. A server that can't be reached is a {@see SessionStoreException} that says
 * which server and what to check.
 */
final class RedisSessionStore implements PersonSessions
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
        private readonly ?ClockInterface $clock = null,
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
        $user = $data[Session::USER_ID] ?? null;
        $index = is_int($user) ? $this->userKey($user) : null;
        $this->call(static function (Redis $redis, string $key) use ($json, $ttl, $index): null {
            $redis->set($key, $json, ['ex' => $ttl]);
            if ($index !== null) {
                // No expiry: a session kept alive by requests that changed nothing outlives any expiry given here.
                // Keys that ended are dropped from the set when it is read.
                $redis->sAdd($index, $key);
            }

            return null;
        }, $id);
    }

    public function forPerson(int $userId): array
    {
        $index = $this->userKey($userId);
        $now = ($this->clock?->now() ?? new DateTimeImmutable())->getTimestamp();
        $timeout = $this->idleTimeout;

        return $this->run(static function (Redis $redis) use ($index, $now, $timeout): array {
            $keys = $redis->sMembers($index);
            $sessions = [];
            foreach (is_array($keys) ? $keys : [] as $key) {
                $ttl = $redis->ttl((string) $key);
                if (!is_int($ttl) || $ttl < 0) {
                    $redis->srem($index, (string) $key);
                    continue;
                }
                $sessions[] = [
                    'last_seen_at' => gmdate(DATE_ATOM, $now + $ttl - $timeout),
                    'expires_at' => gmdate(DATE_ATOM, $now + $ttl),
                ];
            }
            usort($sessions, static fn (array $a, array $b): int => $a['last_seen_at'] <=> $b['last_seen_at']);

            return $sessions;
        });
    }

    /**
     * Deletes the person's session keys and their set.
     */
    public function forgetPerson(int $userId): int
    {
        $index = $this->userKey($userId);

        return $this->run(static function (Redis $redis) use ($index): int {
            $keys = $redis->sMembers($index);
            $keys = is_array($keys) ? array_values(array_map(strval(...), $keys)) : [];
            $ended = $keys === [] ? 0 : $redis->del($keys);
            $redis->del($index);

            return is_int($ended) ? $ended : 0;
        });
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

    private function userKey(int $userId): string
    {
        return $this->prefix . 'user:' . $userId;
    }

    /**
     * @param Closure(Redis, string): mixed $command
     */
    private function call(Closure $command, string $id): mixed
    {
        $key = $this->key($id);

        return $this->run(static fn (Redis $redis): mixed => $command($redis, $key));
    }

    /**
     * @template T
     *
     * @param Closure(Redis): T $command
     *
     * @return T
     */
    private function run(Closure $command): mixed
    {
        $this->redis ??= ($this->connect)();
        try {
            return $command($this->redis);
        } catch (RedisException $exception) {
            $this->redis = null;
            throw new SessionStoreException(sprintf('The Redis server where sign-ins are kept stopped answering: %s.', $exception->getMessage()), 0, $exception);
        }
    }
}
