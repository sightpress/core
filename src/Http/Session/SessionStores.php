<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;

/**
 * Builds a site's {@see SessionStore} from the tenant setting `session.store` (checked when SightPress starts):
 * `database`, `redis`, `files` or a class of your own, which is built from the site's container, so it can ask for
 * the site's {@see Connection}, {@see Site} or {@see TenantConfig}.
 */
final readonly class SessionStores
{
    public function __construct(private TenantConfig $config)
    {
    }

    /**
     * The site container's own entry for a store class of the operator's, or null for core's stores.
     *
     * @return class-string<SessionStore>|null
     */
    public function customClass(): ?string
    {
        $store = $this->config->sessionStore;
        if (in_array($store, [TenantConfig::SESSION_DATABASE, TenantConfig::SESSION_REDIS, TenantConfig::SESSION_FILES], true)) {
            return null;
        }

        /** @var class-string<SessionStore> $store checked by TenantConfig */
        return $store;
    }

    /**
     * @param ContainerInterface $container the site's container
     */
    public function forSite(Site $site, ContainerInterface $container): SessionStore
    {
        $clock = Services::get($container, ClockInterface::class);
        $timeout = $this->config->sessionIdleTimeout;

        return match ($this->config->sessionStore) {
            TenantConfig::SESSION_DATABASE => new DatabaseSessionStore(Services::get($container, Connection::class), $clock, $timeout),
            TenantConfig::SESSION_REDIS => $this->redis($site),
            TenantConfig::SESSION_FILES => new FileSessionStore($this->config->sessionPath, $clock, $timeout),
            default => Services::get($container, SessionStore::class, $this->config->sessionStore),
        };
    }

    private function redis(Site $site): RedisSessionStore
    {
        $server = $this->config->sessionRedis
            ?? throw new SessionStoreException('session.store is "redis", but SESSION_REDIS_DSN is not set.');

        return new RedisSessionStore($server->connect(...), RedisSessionStore::prefixFor($site->id), $this->config->sessionIdleTimeout);
    }
}
