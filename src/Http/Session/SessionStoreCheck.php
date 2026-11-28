<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use Redis;
use SightPress\Core\Tenant\TenantConfig;

/**
 * What `system:check` says about where sessions are kept, and whether that store can be reached (the Redis server of
 * `session.store` = `redis`).
 */
final readonly class SessionStoreCheck
{
    public function __construct(private TenantConfig $config)
    {
    }

    public function describe(): string
    {
        return match ($this->config->sessionStore) {
            TenantConfig::SESSION_DATABASE => 'Sign-ins are kept in each site\'s database.',
            TenantConfig::SESSION_REDIS => sprintf('Sign-ins are kept in Redis at %s.', $this->config->sessionRedis?->address() ?? 'an unknown address'),
            TenantConfig::SESSION_FILES => sprintf('Sign-ins are kept in files in %s, which works on one server only.', $this->config->sessionPath),
            default => sprintf('Sign-ins are kept by %s.', $this->config->sessionStore),
        };
    }

    /**
     * The problem to report, or null when the store can be used.
     */
    public function problem(?Redis $redis = null): ?string
    {
        $server = $this->config->sessionRedis;
        if ($this->config->sessionStore !== TenantConfig::SESSION_REDIS || $server === null) {
            return null;
        }

        try {
            $server->connect($redis ?? new Redis())->close();
        } catch (SessionStoreException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
