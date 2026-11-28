<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Redis;
use RedisException;
use SensitiveParameter;
use SightPress\Core\Http\Session\SessionStoreException;

/**
 * A Redis server, read from a DSN such as `redis://redis:6379`, `rediss://user:secret@cache.example.org:6380/2`
 * (TLS) or `unix:///run/redis/redis.sock`. The password never appears in messages.
 */
final readonly class RedisServer
{
    public const int DEFAULT_PORT = 6379;

    /**
     * @param non-empty-string $host  host name, or the socket path when $port is 0
     * @param int<0, 65535>    $port  0 for a Unix socket
     * @param int<0, max>      $database
     */
    public function __construct(
        public string $host,
        public int $port = self::DEFAULT_PORT,
        public bool $tls = false,
        public ?string $user = null,
        #[SensitiveParameter] public ?string $password = null,
        public int $database = 0,
        public float $timeout = 2.0,
    ) {
    }

    /**
     * Whether PHP's redis extension is installed.
     */
    public static function available(): bool
    {
        return class_exists(Redis::class, false);
    }

    public static function fromDsn(#[SensitiveParameter] string $dsn): self
    {
        if (str_starts_with($dsn, 'unix://')) {
            $path = substr($dsn, 7);
            if ($path === '' || !str_starts_with($path, '/')) {
                throw self::invalid();
            }

            return new self($path, 0);
        }

        $parts = parse_url($dsn);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!in_array($scheme, ['redis', 'rediss'], true) || $host === '') {
            throw self::invalid();
        }

        $path = trim($parts['path'] ?? '', '/');
        if ($path !== '' && preg_match('/^\d{1,2}$/', $path) !== 1) {
            throw self::invalid();
        }

        return new self(
            $host,
            $parts['port'] ?? self::DEFAULT_PORT,
            $scheme === 'rediss',
            isset($parts['user']) && $parts['user'] !== '' ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            max(0, (int) $path),
        );
    }

    /**
     * Where the server is, for messages: never the password.
     */
    public function address(): string
    {
        return $this->port === 0 ? $this->host : sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * A connection to the server, signed in and on the chosen database.
     *
     * @throws SessionStoreException when the server can't be reached or refuses the password
     */
    public function connect(Redis $redis = new Redis()): Redis
    {
        try {
            $connected = $this->port === 0
                ? $redis->connect($this->host)
                : $redis->connect(($this->tls ? 'tls://' : '') . $this->host, $this->port, $this->timeout);
            if (!$connected) {
                throw new RedisException('the connection was refused');
            }
            if ($this->password !== null) {
                $redis->auth($this->user === null ? $this->password : [$this->user, $this->password]);
            }
            if ($this->database !== 0) {
                $redis->select($this->database);
            }
        } catch (RedisException $exception) {
            throw new SessionStoreException(sprintf(
                'SightPress could not reach the Redis server at %s, where sign-ins are kept (session.store = redis, SESSION_REDIS_DSN): %s. Start it, check SESSION_REDIS_DSN, or choose another session.store.',
                $this->address(),
                $exception->getMessage(),
            ), 0, $exception);
        }

        return $redis;
    }

    private static function invalid(): TenantConfigException
    {
        return new TenantConfigException(
            'SESSION_REDIS_DSN must look like redis://host:6379, rediss://user:password@host:6380/1 or unix:///path/to/redis.sock.',
        );
    }
}
