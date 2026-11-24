<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Rbac\Contract\Roles;
use SightPress\Core\Template\Message;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/roles/{name}/delete`: asks before deleting a role, or says why it can't be deleted (built in, the sign-up
 * role, or people still have it). Needs `role.manage` (X-4).
 */
final readonly class DeleteRoleHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private Roles $roles,
    ) {
    }

    public static function path(string $name): string
    {
        return RoleHandler::path($name) . '/delete';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RolesHandler::actor($request);
        $name = $request->getAttribute(RoleHandler::NAME_ATTRIBUTE);
        $role = (is_string($name) ? $this->roles->find($name) : null) ?? throw new HttpNotFoundException($request);
        $problem = $this->roles->deleteProblem($role->name);
        if ($request->getMethod() === 'POST' && $problem === null) {
            $this->roles->delete($actor, $role->name);

            return $this->screens->redirect($request, RolesHandler::PATH, new Message('Deleted the role “{role}”.', ['role' => Message::of($role->label)]));
        }

        return $this->screens->render($request, '@people/delete-role.html.twig', [
            'role' => $role,
            'problem' => $problem,
            'error_count' => $request->getMethod() === 'POST' ? 1 : 0,
            'action' => self::path($role->name),
            'cancel' => RoleHandler::path($role->name),
        ], $request->getMethod() === 'POST' ? 422 : 200);
    }
}
