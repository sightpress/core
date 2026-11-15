<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Site\SiteModule;
use SightPress\Core\User\Provisioning\CreateFirstAdmin;
use SightPress\Core\User\UserMigrations;
use SightPress\Core\User\UserModule;
use SightPress\Core\User\UserRepository;

#[CoversClass(UserModule::class)]
#[CoversClass(UserMigrations::class)]
#[UsesNamespace('SightPress\Core\Module')]
#[UsesNamespace('SightPress\Core\Site')]
final class UserModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsTableRepositoryAndPermissions(): void
    {
        $module = new UserModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue([
                new ModuleCandidate(new SiteModule(), 'sightpress/core'),
                new ModuleCandidate($module, 'sightpress/core'),
            ])->resolve(new ModuleSelection()),
        );

        self::assertSame('user', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        self::assertSame('SightPress\Core\User\Migrations', $registry->migrations()[1]->item->namespace());
        self::assertDirectoryExists($registry->migrations()[1]->item->directory());
        $listener = $registry->listeners()[0]->item;
        self::assertSame([SiteProvisioning::class, CreateFirstAdmin::class, SiteProvisioning::ACCOUNTS], [$listener->event, $listener->listener, $listener->priority]);
        self::assertTrue($registry->services()[UserRepository::class]->item->public);
        self::assertSame(
            ['user.invite', 'user.manage'],
            array_map(static fn ($registered) => $registered->item->name, $registry->permissions()),
        );
        foreach ($registry->permissions() as $registered) {
            self::assertFalse($registered->item->allowedOnSignUp);
        }
    }
}
