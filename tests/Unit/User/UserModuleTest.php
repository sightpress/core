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
#[UsesNamespace('SightPress\Core\User')]
#[UsesNamespace('SightPress\Core\Infrastructure')]
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
        self::assertSame(UserRepository::class, $registry->services()[\SightPress\Core\User\Contract\AccountDirectory::class]->item->class);
        self::assertSame(UserRepository::class, $registry->services()[\SightPress\Core\User\Contract\AccountPreferences::class]->item->class);
        $factory = $registry->services()[\SightPress\Core\User\Avatar\AvatarService::class]->item->factory;
        self::assertNotNull($factory);
        self::assertInstanceOf(\SightPress\Core\User\Avatar\AvatarService::class, $factory(new \DI\ContainerBuilder()->addDefinitions([
            \League\Flysystem\FilesystemOperator::class => new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter(sys_get_temp_dir(), lazyRootCreation: true)),
            \Psr\Clock\ClockInterface::class => new \SightPress\Core\Tests\Support\FixedClock(),
        ])->build()));
        self::assertSame(
            ['user.account' => ['/account', ['GET', 'POST']], 'user.account.picture' => ['/account/picture', ['POST']], 'user.account.picture.remove' => ['/account/picture/remove', ['POST']], 'user.avatar' => ['/avatars/{file}', ['GET', 'HEAD']]],
            array_combine(
                array_map(static fn ($r) => $r->item->name, $registry->routes()),
                array_map(static fn ($r) => [$r->item->path, $r->item->methods], $registry->routes()),
            ),
        );
        self::assertSame([], array_values(array_filter($registry->routes(), static fn ($r) => $r->item->middleware !== [])), '/account checks sign-in itself, for members too');
        self::assertDirectoryExists($registry->views()[0]->item->directory);
        self::assertSame(
            ['user.invite', 'user.manage'],
            array_map(static fn ($registered) => $registered->item->name, $registry->permissions()),
        );
        foreach ($registry->permissions() as $registered) {
            self::assertFalse($registered->item->allowedOnSignUp);
        }
    }
}
