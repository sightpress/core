<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Admin\AdminShell;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\User\Entity\User;

/**
 * Admin screens for unit tests: the sign-in fixture (site database, templates, mail) plus the admin shell with the
 * navigation of core's modules, and requests made by a signed-in person.
 */
final class AdminFixture
{
    public readonly AuthFixture $auth;

    public function __construct()
    {
        $this->auth = new AuthFixture();
    }

    public function shell(): AdminShell
    {
        return new AdminShell(
            $this->auth->templates,
            $this->auth->http,
            AdminNavigation::fromRegistry($this->auth->database->registry),
            $this->auth->users,
            $this->auth->site,
        );
    }

    /**
     * A request from the user, with the permissions their role gives them (or none for null).
     *
     * @param array<string, mixed> $body
     */
    public function request(?User $user, string $method = 'GET', string $path = '/admin', array $body = [], ?Session $session = null): ServerRequestInterface
    {
        /** @var array<string, string> $body */
        $request = $this->auth->request($method, 'https://demo.example' . $path, $body, session: $session);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($user === null) {
            return $request;
        }
        $id = (int) $user->id();

        return $request->withAttribute(Actor::class, new Actor($id, $this->auth->database->roles()->permissionNamesOf($id)));
    }
}
