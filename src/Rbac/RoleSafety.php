<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Rbac\Entity\Role;

/**
 * The sign-up guardrail (ADR-0014): which roles people who register themselves may get.
 */
final class RoleSafety
{
    /**
     * A role is sign-up-safe when every permission it holds is allowed on sign-up. `admin` never is, by name;
     * `member` always is, as it holds nothing.
     */
    public static function isSignUpSafe(Role $role): bool
    {
        if ($role->name() === RoleName::ADMIN) {
            return false;
        }

        foreach ($role->permissions() as $permission) {
            if (!$permission->allowedOnSignUp()) {
                return false;
            }
        }

        return true;
    }
}
