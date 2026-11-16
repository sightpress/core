<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\People;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\People\AnyEmailPolicy;
use SightPress\Core\People\EmailPolicy;
use SightPress\Core\People\Http\AcceptInvitationHandler;
use SightPress\Core\People\Http\InviteHandler;
use SightPress\Core\People\PeopleMigrations;
use SightPress\Core\People\PeopleModule;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;

#[CoversClass(PeopleModule::class)]
#[CoversClass(PeopleMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class PeopleModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersInvitations(): void
    {
        $module = new PeopleModule();
        $registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')->catalogue(CoreModules::candidates())->resolve(new ModuleSelection()),
        );

        self::assertSame('people', $module->manifest()->id);
        self::assertTrue($module->manifest()->required);
        self::assertSame(['site', 'user', 'rbac', 'auth', 'admin'], array_map(static fn ($r) => $r->moduleId, $module->manifest()->requires));
        $migrations = array_values(array_filter($registry->migrations(), static fn ($m) => $m->module === 'people'));
        self::assertSame('SightPress\Core\People\Migrations', $migrations[0]->item->namespace());
        self::assertDirectoryExists($migrations[0]->item->directory());
        self::assertSame(AnyEmailPolicy::class, $registry->services()[EmailPolicy::class]->item->class);
        self::assertFalse($registry->services()[EmailPolicy::class]->item->public);
        $routes = [];
        foreach (array_filter($registry->routes(), static fn ($r) => $r->module === 'people') as $route) {
            $routes[$route->item->name] = [$route->item->path, $route->item->handler, $route->item->middleware];
        }
        self::assertSame([
            'people.invite' => ['/admin/users/invite', InviteHandler::class, [AdminAccessMiddleware::class]],
            'people.invitation' => ['/invite/{token}', AcceptInvitationHandler::class, []],
        ], $routes);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'people'));
        self::assertSame(['people.invite', 'Invite someone', 'user.invite'], [$menu[0]->item->id, $menu[0]->item->label, $menu[0]->item->permission]);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'people'));
        self::assertFileExists($views[0]->item->directory . '/email/invitation.txt.twig');
    }
}
