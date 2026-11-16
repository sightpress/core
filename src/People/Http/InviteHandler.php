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
use SightPress\Core\People\EmailPolicy;
use SightPress\Core\People\InvitationMailer;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\RoleChoice;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\User\Contract\Accounts;
use Slim\Exception\HttpForbiddenException;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/admin/users/invite`: invite someone by email with a role. The role choice lists only the roles the inviter may
 * give (D3: editors give only `contributor`), and the server refuses any other, whatever is sent.
 */
final readonly class InviteHandler implements RequestHandlerInterface
{
    public const string PATH = '/admin/users/invite';
    public const string PERMISSION = 'user.invite';

    public function __construct(
        private AdminScreens $screens,
        private FormValidator $validator,
        private RoleGrants $roles,
        private Accounts $accounts,
        private Invitations $invitations,
        private InvitationMailer $mailer,
        private EmailPolicy $emailPolicy,
    ) {
    }

    /**
     * Radio choices for roles: the name, then what the role can do.
     *
     * @param list<RoleChoice> $roles
     *
     * @return array<string, string>
     */
    public static function choices(array $roles): array
    {
        $choices = [];
        foreach ($roles as $role) {
            $choices[$role->name] = $role->label . ': ' . $role->description;
        }

        return $choices;
    }

    /**
     * @param list<RoleChoice> $roles
     */
    public static function form(array $roles): Form
    {
        $choices = self::choices($roles);
        $length = static fn (string $label): Length => new Length(max: 100, maxMessage: sprintf('%s must be 100 characters or fewer', $label));

        $form = Form::create(
            'invite',
            new Field('email', 'Email address', FieldType::Email, hint: 'We send the invitation to this address.', autocomplete: 'off'),
            new Field('role', 'Role', FieldType::Radios, choices: $choices),
            new Field('first_name', 'First name', hint: 'They can change it when they accept.', required: false, constraints: [$length('First name')], autocomplete: 'off'),
            new Field('last_name', 'Last name', required: false, constraints: [$length('Last name')], autocomplete: 'off'),
        );

        return count($roles) === 1 ? $form->withValues(['role' => $roles[0]->name]) : $form;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!$actor->has(self::PERMISSION)) {
            throw new HttpForbiddenException($request);
        }
        $roles = $this->roles->grantableBy($actor);
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form($roles));
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(self::form($roles), is_array($body) ? $body : []);
        if (!$form->isValid()) {
            return $this->show($request, $form, 422);
        }
        $email = Invitations::normalizeEmail($form->text('email'));
        $role = $form->text('role');
        $problem = $this->emailPolicy->problem($email)
            ?? ($this->accounts->findAccountByEmail($email) === null ? null : sprintf('There is already an account for %s.', $email));
        if ($problem !== null) {
            return $this->show($request, $form->withErrors(['email' => $problem]), 422);
        }
        if (!$this->roles->canGrant($actor, $role)) {
            return $this->show($request, $form->withErrors(['role' => 'Select a role you are allowed to give.']), 422);
        }

        $first = $form->text('first_name');
        $last = $form->text('last_name');
        [$invitation, $token] = $this->invitations->create($email, $role, $first === '' ? null : $first, $last === '' ? null : $last, $actor->userId);
        $inviter = $actor->userId === null ? null : $this->accounts->findAccount($actor->userId);
        $label = '';
        foreach ($roles as $choice) {
            $label = $choice->name === $role ? $choice->label : $label;
        }
        $this->mailer->send($request, $invitation, $token, $inviter?->effectiveName() ?? 'Someone', $label);

        return $this->screens->redirect($request, self::PATH, sprintf(
            'We have sent an invitation to %s to join as %s. The link works for 7 days.',
            $email,
            mb_strtolower($label),
        ));
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        return $this->screens->render($request, '@people/invite.html.twig', ['form' => $form], $status);
    }
}
