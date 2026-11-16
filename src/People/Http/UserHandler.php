<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\User\Contract\AccountRoster;
use SightPress\Core\User\Contract\AccountSummary;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/users/{id}`: one person's details, their role (which can be changed) and whether they can sign in
 * ({@see UserStatusHandler}). Nobody changes their own role or status, so the site always keeps at least one active
 * person who manages users. Needs `user.manage`.
 */
final readonly class UserHandler implements RequestHandlerInterface
{
    public const string ID_ATTRIBUTE = 'id';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private AccountRoster $accounts,
        private RoleGrants $roles,
    ) {
    }

    public static function path(int $id): string
    {
        return UsersHandler::PATH . '/' . $id;
    }

    /**
     * @param list<RoleChoice> $roles
     */
    public static function form(array $roles, ?RoleChoice $current): Form
    {
        $form = Form::create('role', new Field('role', 'Role', FieldType::Radios, choices: InviteHandler::choices($roles)));

        return $current === null ? $form : $form->withValues(['role' => $current->name]);
    }

    /**
     * The person the request is about, after checking the actor may manage people.
     *
     * @return array{Actor, AccountSummary}
     */
    public static function target(ServerRequestInterface $request, AccountRoster $accounts): array
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(UsersHandler::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $id = $request->getAttribute(self::ID_ATTRIBUTE);
        $account = is_numeric($id) ? $accounts->summary((int) $id) : null;

        return [$actor, $account ?? throw new HttpNotFoundException($request)];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$actor, $account] = self::target($request, $this->accounts);
        $current = $this->roles->roleOf($account->id);
        $choices = $this->roles->grantableBy($actor);
        $self = $actor->userId === $account->id;
        if ($request->getMethod() !== 'POST' || $self) {
            return $this->show($request, $account, $current, self::form($choices, $current), $self);
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(self::form($choices, $current), is_array($body) ? $body : []);
        $role = $form->text('role');
        if ($form->isValid() && !$this->roles->canGrant($actor, $role)) {
            $form = $form->withErrors(['role' => 'Select a role you are allowed to give.']);
        }
        if (!$form->isValid()) {
            return $this->show($request, $account, $current, $form, false, 422);
        }
        if ($role === $current?->name) {
            return $this->screens->redirect($request, self::path($account->id), sprintf('%s already has this role. Nothing was changed.', $account->effectiveName));
        }
        $this->roles->change($actor, $account->id, $role);

        return $this->screens->redirect($request, self::path($account->id), sprintf(
            '%s’s role is now %s.',
            $account->effectiveName,
            $this->roles->find($role)->label ?? $role,
        ));
    }

    private function show(ServerRequestInterface $request, AccountSummary $account, ?RoleChoice $current, Form $form, bool $self, int $status = 200): ResponseInterface
    {
        return $this->screens->render($request, '@people/user.html.twig', [
            'account' => $account,
            'role' => $current,
            'status' => UsersHandler::STATUSES[$account->status] ?? $account->status,
            'form' => $form,
            'self' => $self,
            'path' => self::path($account->id),
        ], $status);
    }
}
