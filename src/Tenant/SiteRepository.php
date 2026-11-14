<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * The tenant's sites (ADR-0002). Backed by `config/sites.php` today; a database-backed registry for very large
 * tenants can replace it later without changing callers.
 *
 * Only CLI commands call {@see save()} and {@see remove()}; the web process only reads.
 */
interface SiteRepository
{
    /**
     * @return array<non-empty-string, Site> keyed by slug, sorted by slug
     */
    public function all(): array;

    public function find(string $slug): ?Site;

    /**
     * The site a host name points at, whatever its status. Case and a trailing dot are ignored.
     */
    public function findByDomain(string $domain): ?Site;

    /**
     * Adds the site, or replaces the one with the same slug.
     */
    public function save(Site $site): void;

    public function remove(string $slug): void;
}
