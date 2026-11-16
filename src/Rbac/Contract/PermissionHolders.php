<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * Who holds a permission on the site, for example everyone who may publish (to ask them for a review).
 */
interface PermissionHolders
{
    /**
     * Ids of the users who hold the permission through any of their roles, in ascending order.
     *
     * @return list<int>
     */
    public function userIdsWith(string $permission): array;
}
