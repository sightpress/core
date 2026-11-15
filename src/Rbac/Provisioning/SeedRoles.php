<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Provisioning;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RbacException;
use SightPress\Core\Rbac\RoleName;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\Contract\SiteProvisioning;

/**
 * New site, step {@see SiteProvisioning::ROLES}: seeds the permission catalogue and the four roles, and gives the
 * first admin the admin role.
 */
final readonly class SeedRoles
{
    public function __invoke(SiteProvisioning $event): void
    {
        $report = Services::get($event->services, RbacSeeder::class)->seed();
        $event->report($report->addedRoles === []
            ? 'The roles were already set up.'
            : sprintf('Set up the roles %s.', self::names($report->addedRoles)));

        $adminId = $event->adminId();
        if ($adminId === null) {
            return;
        }
        $roles = Services::get($event->services, RoleRepository::class);
        $admin = $roles->findRole(RoleName::ADMIN) ?? throw new RbacException('The admin role is missing after seeding.');
        $roles->assign($adminId, $admin);
        $event->report('Gave the first admin the admin role.');
    }

    /**
     * "a", "a and b", "a, b and c".
     *
     * @param non-empty-list<string> $names
     */
    private static function names(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
    }
}
