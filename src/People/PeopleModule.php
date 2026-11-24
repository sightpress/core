<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use SightPress\Core\People\Command\CreateRoleCommand;
use SightPress\Core\People\Command\DeleteRoleCommand;
use SightPress\Core\People\Command\GrantRoleCommand;
use SightPress\Core\People\Command\ListRolesCommand;
use SightPress\Core\People\Command\RevokeRoleCommand;
use SightPress\Core\People\Http\AcceptInvitationHandler;
use SightPress\Core\People\Http\DeleteRoleHandler;
use SightPress\Core\People\Http\RoleHandler;
use SightPress\Core\People\Http\RolesHandler;
use SightPress\Core\People\Http\InvitationCodeHandler;
use SightPress\Core\People\Http\InvitationPages;
use SightPress\Core\People\Http\InviteHandler;
use SightPress\Core\People\Http\UserHandler;
use SightPress\Core\People\Http\UsersHandler;
use SightPress\Core\People\Http\SignUpSettingsHandler;
use SightPress\Core\People\Http\UserStatusHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The people who run a site: invitations (admins invite with any role, editors invite contributors — D3) with an
 * invitation code, the "Accept an invitation" pages where invited people type it and set up their account, and
 * the admin screens for users, for roles (X-4) and for who may sign up (the sign-up pages themselves are the auth
 * module's), and the `site:role:*` commands. Always on.
 */
final class PeopleModule implements ModuleInterface
{
    public const string ID = 'people';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            self::ID,
            '0.1.0',
            [
                new ModuleRequirement('site', '^0.1'),
                new ModuleRequirement('user', '^0.1'),
                new ModuleRequirement('rbac', '^0.1'),
                new ModuleRequirement('auth', '^0.1'),
                new ModuleRequirement('admin', '^0.1'),
            ],
            required: true,
        );
    }

    public function register(ModuleRegistrar $registrar): void
    {
        $registrar->migrations()->add(new PeopleMigrations());
        $registrar->views()->add(self::ID, dirname(__DIR__, 2) . '/resources/views/people');

        $services = $registrar->services();
        foreach ([Invitations::class, InvitationMailer::class, InviteHandler::class, InvitationPages::class, InvitationCodeHandler::class, AcceptInvitationHandler::class, UsersHandler::class, UserHandler::class, UserStatusHandler::class, SignUpSettingsHandler::class, RolesHandler::class, RoleHandler::class, DeleteRoleHandler::class] as $class) {
            $services->autowire($class);
        }

        $routes = $registrar->routes();
        $user = UsersHandler::PATH . '/{' . UserHandler::ID_ATTRIBUTE . ':[0-9]+}';
        $routes->add(new Route('people.users', UsersHandler::PATH, UsersHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.user', $user, UserHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.user.status', $user . '/status', UserStatusHandler::class, ['POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.invite', InviteHandler::PATH, InviteHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.invitation', InvitationCodeHandler::PATH, InvitationCodeHandler::class, ['GET', 'POST']));
        $routes->add(new Route('people.invitation.account', AcceptInvitationHandler::PATH, AcceptInvitationHandler::class, ['GET', 'POST']));
        $routes->add(new Route('people.sign_up', SignUpSettingsHandler::PATH, SignUpSettingsHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('people.users', 'People', 'people.users', UsersHandler::PERMISSION, position: 40));
        $role = RolesHandler::PATH . '/{' . RoleHandler::NAME_ATTRIBUTE . ':[a-z][a-z0-9_]*}';
        $routes->add(new Route('people.roles', RolesHandler::PATH, RolesHandler::class, middleware: [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.role.new', RoleHandler::NEW_PATH, RoleHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.role', $role, RoleHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route('people.role.delete', $role . '/delete', DeleteRoleHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $registrar->adminMenu()->add(new AdminMenuItem('people.invite', 'Invite someone', 'people.invite', InviteHandler::PERMISSION, position: 50));
        $registrar->adminMenu()->add(new AdminMenuItem('people.roles', 'Roles', 'people.roles', RolesHandler::PERMISSION, position: 52));
        foreach ([ListRolesCommand::class, CreateRoleCommand::class, GrantRoleCommand::class, RevokeRoleCommand::class, DeleteRoleCommand::class] as $command) {
            $registrar->commands()->add($command);
        }
    }
}
