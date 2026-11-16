<?php

declare(strict_types=1);

namespace SightPress\Core\User;

use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\User\Contract\AccountDirectory;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Provisioning\CreateFirstAdmin;
use SightPress\Module\Access\Permission;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * People with an account on a site: the `users` table, the repository, and the first admin's account on a new
 * site. Always on.
 */
final class UserModule implements ModuleInterface
{
    public const string ID = 'user';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::ID, '0.1.0', [new ModuleRequirement('site', '^0.1')], required: true);
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new UserMigrations());
        $registrar->services()->autowire(UserRepository::class, public: true);
        $registrar->services()->autowire(UserRepository::class, Accounts::class, public: true);
        $registrar->services()->autowire(UserRepository::class, AccountDirectory::class, public: true);
        $registrar->subscribers()->listen(SiteProvisioning::class, CreateFirstAdmin::class, SiteProvisioning::ACCOUNTS);
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
