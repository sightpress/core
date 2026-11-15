<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Core\User\Contract\Accounts;
use SightPress\Module\Access\Permission;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;

/**
 * People with an account on a site: the `users` table and the repository. Always on.
 */
final class UserModule implements ModuleInterface
{
    public const string ID = 'user';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new UserMigrations());
        $registrar->services()->autowire(UserRepository::class, public: true);
        $registrar->services()->autowire(UserRepository::class, Accounts::class, public: true);
        $registrar->permissions()->add(new Permission(
            'user.invite',
            'Invite people',
            'Invite people to the site with a role they are allowed to give.',
            ['admin', 'editor'],
        ));
        $registrar->permissions()->add(new Permission(
            'user.manage',
            'Manage users',
            'Change the role of any user, and deactivate accounts.',
            ['admin'],
        ));
    }
}
