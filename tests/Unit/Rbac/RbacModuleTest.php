<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Rbac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\CorePermissions;
use SightPress\Core\Rbac\VoterAuthorizer;
use SightPress\Core\Rbac\RbacMigrations;
use SightPress\Core\Rbac\RbacModule;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RegistrationRole;
use SightPress\Core\Rbac\RoleManager;
use SightPress\Core\Rbac\RoleName;
use SightPress\Core\Rbac\RoleRepository;

#[CoversClass(RbacModule::class)]
#[CoversClass(RbacMigrations::class)]
#[CoversClass(CorePermissions::class)]
#[CoversClass(RoleName::class)]
#[UsesNamespace('SightPress\Core')]
final class RbacModuleTest extends TestCase
{
    public function testIsRequiredNeedsSitesAndUsersAndRegistersTheCatalogue(): void
    {
        $module = new RbacModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('rbac', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(
            ['site', 'user'],
            array_map(static fn ($requirement) => $requirement->moduleId, $module->manifest()->requires),
        );
        self::assertSame(['site', 'user', 'rbac', 'auth'], $registry->modules());

        $migrations = new RbacMigrations();
        self::assertSame('SightPress\Core\Rbac\Migrations', $migrations->namespace());
        self::assertDirectoryExists($migrations->directory());

        foreach ([RoleRepository::class, RegistrationRole::class, RoleManager::class, RbacSeeder::class, Authorizer::class] as $id) {
            self::assertTrue($registry->services()[$id]->item->public, $id);
        }
        $factory = $registry->services()[Authorizer::class]->item->factory;
        self::assertNotNull($factory);
        self::assertInstanceOf(VoterAuthorizer::class, $factory(new \DI\Container()));
    }

    public function testOnlyFivePermissionsAreAllowedOnSignUp(): void
    {
        $safe = [];
        foreach (CorePermissions::all() as $permission) {
            if ($permission->allowedOnSignUp) {
                $safe[] = $permission->name;
            }
            self::assertNotContains(RoleName::MEMBER, $permission->defaultRoles);
        }

        self::assertSame(['admin.access', 'page.create', 'page.edit.own', 'page.submit', 'media.upload'], $safe);
    }

    public function testFourRolesAreSeededOfWhichAdminAndMemberAreSystemRoles(): void
    {
        $seeded = RoleName::seeded();

        self::assertSame(['admin', 'editor', 'contributor', 'member'], array_keys($seeded));
        self::assertSame(
            ['admin', 'member'],
            array_keys(array_filter($seeded, static fn (array $role): bool => $role['system'])),
        );
    }
}
