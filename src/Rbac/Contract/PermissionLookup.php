<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * What a user may do on the site.
 */
interface PermissionLookup
{
    /**
     * Names of every permission the user holds through any of their roles, sorted.
     *
     * @return list<string>
     */
    public function permissionNamesOf(int $userId): array;
}
