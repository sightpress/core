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
use SightPress\Core\User\UserMigrations;
use SightPress\Core\User\UserModule;
use SightPress\Core\User\UserRepository;

#[CoversClass(UserModule::class)]
#[CoversClass(UserMigrations::class)]
#[UsesNamespace('SightPress\Core\Module')]
final class UserModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersItsTableRepositoryAndPermissions(): void
    {
        $module = new UserModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue([new ModuleCandidate($module, 'sightpress/core')])->resolve(new ModuleSelection()),
        );

        self::assertSame('user', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame('SightPress\Core\User\Migrations', $registry->migrations()[0]->item->namespace());
        self::assertDirectoryExists($registry->migrations()[0]->item->directory());
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
