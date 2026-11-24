<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Core\Rbac\Contract\RoleSummary;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/roles` — "Roles": every role of the site in a table (how many permissions it has, how many people have
 * it, whether it is built in or the sign-up role), each name linking to its page, and "Create a role". Needs
 * `role.manage` (X-4).
 */
final readonly class RolesHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/roles';

    public const string PERMISSION = Roles::PERMISSION;

    public function __construct(
        private AdminScreens $screens,
        private Roles $roles,
    ) {
    }

    /**
     * The actor, after checking they may manage roles.
     */
    public static function actor(ServerRequestInterface $request): Actor
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }

        return $actor;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        self::actor($request);

        return $this->screens->render($request, '@people/roles.html.twig', [
            'rows' => array_map(static fn (RoleSummary $role): array => [
                'role' => $role,
                'path' => RoleHandler::path($role->name),
            ], $this->roles->roles()),
            'new' => RoleHandler::NEW_PATH,
        ]);
    }
}
