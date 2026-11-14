<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;

/**
 * Roles and permissions: core's permission catalogue, the RBAC tables, the seeder, the guardrails (D9, D24,
 * ADR-0014) and the {@see Authorizer} with its voters. Always on.
 */
final class RbacModule implements ModuleInterface
{
    public const string ID = 'rbac';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [new ModuleRequirement('site', '^0.1'), new ModuleRequirement('user', '^0.1')],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        foreach (CorePermissions::all() as $permission) {
            $registrar->permissions()->add($permission);
        }
        $registrar->migrations()->add(new RbacMigrations());
        $registrar->services()->autowire(RoleRepository::class, public: true);
        $registrar->services()->autowire(RegistrationRole::class, public: true);
        $registrar->services()->autowire(RoleManager::class, public: true);
        $registrar->services()->autowire(RbacSeeder::class, public: true);
        $registrar->services()->factory(
            Authorizer::class,
            static fn (): Authorizer => new VoterAuthorizer([new PageVoter()]),
            public: true,
        );
    }
}
