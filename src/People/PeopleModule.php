<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use SightPress\Core\People\Http\AcceptInvitationHandler;
use SightPress\Core\People\Http\InviteHandler;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Module\Admin\AdminMenuItem;
use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\ModuleRequirement;
use SightPress\Module\Routing\Route;

/**
 * The people who run a site: invitations (admins invite with any role, editors invite contributors — D3), the
 * page where invited people set up their account, and the admin screens for users. Always on.
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
        $services->autowire(AnyEmailPolicy::class, EmailPolicy::class);
        foreach ([Invitations::class, InvitationMailer::class, InviteHandler::class, AcceptInvitationHandler::class] as $class) {
            $services->autowire($class);
        }

        $routes = $registrar->routes();
        $routes->add(new Route('people.invite', InviteHandler::PATH, InviteHandler::class, ['GET', 'POST'], [AdminAccessMiddleware::class]));
        $routes->add(new Route(
            'people.invitation',
            InvitationMailer::PATH . '{' . AcceptInvitationHandler::TOKEN_ATTRIBUTE . '}',
            AcceptInvitationHandler::class,
            ['GET', 'POST'],
        ));
        $registrar->adminMenu()->add(new AdminMenuItem('people.invite', 'Invite someone', 'people.invite', InviteHandler::PERMISSION, position: 50));
    }
}
