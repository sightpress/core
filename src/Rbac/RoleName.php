<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

/**
 * Names of the roles every site starts with.
 */
final class RoleName
{
    /** Everything; a system role. */
    public const string ADMIN = 'admin';

    /** Writes, reviews and publishes everyone's pages, manages media and menus, invites contributors. */
    public const string EDITOR = 'editor';

    /** Writes their own pages and submits them for review. */
    public const string CONTRIBUTOR = 'contributor';

    /** Reads published pages and looks after their own account; holds no permissions, ever (D24). A system role. */
    public const string MEMBER = 'member';

    /**
     * @return array<string, array{label: string, system: bool}> the seeded roles, keyed by name
     */
    public static function seeded(): array
    {
        return [
            self::ADMIN => ['label' => 'Admin', 'system' => true],
            self::EDITOR => ['label' => 'Editor', 'system' => false],
            self::CONTRIBUTOR => ['label' => 'Contributor', 'system' => false],
            self::MEMBER => ['label' => 'Member', 'system' => true],
        ];
    }
}
