<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

/**
 * The current site's stored settings, as text values keyed by setting key (for example `rbac.registration_role`).
 *
 * It only stores values. The module that owns a setting validates it before calling {@see set()}.
 */
interface SiteSettings
{
    /**
     * The stored value, or null when the site has never stored one.
     */
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;
}
