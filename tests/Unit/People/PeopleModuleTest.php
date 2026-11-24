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
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\People\Http\AcceptInvitationHandler;
use SightPress\Core\People\Http\InvitationCodeHandler;
use SightPress\Core\People\Http\InviteHandler;
use SightPress\Core\People\PeopleMigrations;
use SightPress\Core\People\PeopleModule;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;

#[CoversClass(PeopleModule::class)]
#[CoversClass(PeopleMigrations::class)]
#[UsesNamespace('SightPress\Core')]
final class PeopleModuleTest extends TestCase
{
    public function testIsRequiredAndRegistersInvitationsAndTheUserScreens(): void
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
        self::assertSame('auth', $registry->services()[EmailPolicy::class]->module, 'the auth module owns the email policy');
        $routes = [];
        foreach (array_filter($registry->routes(), static fn ($r) => $r->module === 'people') as $route) {
            $routes[$route->item->name] = [$route->item->path, $route->item->handler, $route->item->middleware];
        }
        self::assertSame([
            'people.users' => ['/admin/users', \SightPress\Core\People\Http\UsersHandler::class, [AdminAccessMiddleware::class]],
            'people.user' => ['/admin/users/{id:[0-9]+}', \SightPress\Core\People\Http\UserHandler::class, [AdminAccessMiddleware::class]],
            'people.user.status' => ['/admin/users/{id:[0-9]+}/status', \SightPress\Core\People\Http\UserStatusHandler::class, [AdminAccessMiddleware::class]],
            'people.invite' => ['/admin/users/invite', InviteHandler::class, [AdminAccessMiddleware::class]],
            'people.invitation' => ['/login/invitation', InvitationCodeHandler::class, []],
            'people.invitation.account' => ['/login/invitation/account', AcceptInvitationHandler::class, []],
            'people.sign_up' => ['/admin/settings/sign-up', \SightPress\Core\People\Http\SignUpSettingsHandler::class, [AdminAccessMiddleware::class]],
            'people.roles' => ['/admin/roles', \SightPress\Core\People\Http\RolesHandler::class, [AdminAccessMiddleware::class]],
            'people.role.new' => ['/admin/roles/new', \SightPress\Core\People\Http\RoleHandler::class, [AdminAccessMiddleware::class]],
            'people.role' => ['/admin/roles/{name:[a-z][a-z0-9_]*}', \SightPress\Core\People\Http\RoleHandler::class, [AdminAccessMiddleware::class]],
            'people.role.delete' => ['/admin/roles/{name:[a-z][a-z0-9_]*}/delete', \SightPress\Core\People\Http\DeleteRoleHandler::class, [AdminAccessMiddleware::class]],
        ], $routes);
        $menu = array_values(array_filter($registry->adminMenu(), static fn ($m) => $m->module === 'people'));
        self::assertSame(['people.users', 'People', 'user.manage'], [$menu[0]->item->id, $menu[0]->item->label, $menu[0]->item->permission]);
        self::assertSame(['people.invite', 'Invite someone', 'user.invite'], [$menu[1]->item->id, $menu[1]->item->label, $menu[1]->item->permission]);
        self::assertSame(['people.roles', 'Roles', 'role.manage'], [$menu[2]->item->id, $menu[2]->item->label, $menu[2]->item->permission]);
        $commands = array_map(static fn ($c) => $c->item, array_values(array_filter($registry->commands(), static fn ($c) => $c->module === 'people')));
        self::assertSame([\SightPress\Core\People\Command\ListRolesCommand::class, \SightPress\Core\People\Command\CreateRoleCommand::class, \SightPress\Core\People\Command\GrantRoleCommand::class, \SightPress\Core\People\Command\RevokeRoleCommand::class, \SightPress\Core\People\Command\DeleteRoleCommand::class], $commands);
        $views = array_values(array_filter($registry->views(), static fn ($v) => $v->module === 'people'));
        self::assertFileExists($views[0]->item->directory . '/email/invitation.txt.twig');
        self::assertFileExists($views[0]->item->directory . '/sign-up.html.twig');
    }
}
