<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use SightPress\Core\Template\Message;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Form\TimezoneChoices;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\People\EmailPolicy;
use SightPress\Core\People\Invitation;
use SightPress\Core\People\Invitations;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountRegistration;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/login/invitation/account`: once {@see InvitationCodeHandler} checked the invitation code, the person who was
 * invited sets up their account (for {@see self::VERIFIED_FOR} seconds, while the invitation is still open). They give their names (prefilled with what
 * the inviter knew), an optional display name, their time zone (prefilled from the browser when it can say) and a
 * password that meets the site's policy (spelled out in the hint); the account gets its avatar and the role of the invitation, and they are asked to sign in.
 */
final readonly class AcceptInvitationHandler implements RequestHandlerInterface
{
    public const string PATH = '/login/invitation/account';

    /** How long a checked code lets someone set up their account, in seconds. */
    public const int VERIFIED_FOR = 3600;

    private const string SESSION = 'people.invitation';

    public function __construct(
        private InvitationPages $pages,
        private ResponseFactoryInterface $responses,
        private FormValidator $validator,
        private Invitations $invitations,
        private AccountRegistration $accounts,
        private RoleGrants $roles,
        private Passwords $passwords,
        private EmailPolicy $emailPolicy,
        private Site $site,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The invitation code was right: this session may set up the account.
     */
    public static function remember(Session $session, Invitation $invitation, int $now): void
    {
        $session->set(self::SESSION, ['id' => $invitation->id, 'until' => $now + self::VERIFIED_FOR]);
        $session->regenerate();
    }

    public static function form(Invitation $invitation, string $timezone, string|Message $passwordHint): Form
    {
        $length = static fn (string $label): Length => new Length(max: 100, maxMessage: '{field} must be {{ limit }} characters or fewer');

        return Form::create(
            'accept',
            new Field('first_name', 'First name', autocomplete: 'given-name', constraints: [$length('First name')]),
            new Field('last_name', 'Last name', autocomplete: 'family-name', constraints: [$length('Last name')]),
            new Field('display_name', 'Display name', hint: 'Shown next to your work and in emails. Your first name is shown if you leave it empty.', required: false, constraints: [$length('Display name')], autocomplete: 'nickname'),
            new Field('timezone', 'Time zone', FieldType::Select, hint: 'Dates and times are shown in this time zone.', choices: TimezoneChoices::grouped()),
            new Field('password', 'Password', FieldType::Password, hint: $passwordHint, autocomplete: 'new-password'),
        )->withValues([
            'first_name' => $invitation->firstName ?? '',
            'last_name' => $invitation->lastName ?? '',
            'timezone' => $timezone,
        ]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = Session::fromRequest($request);
        $remembered = $session->get(self::SESSION);
        $invitation = is_array($remembered) && is_int($remembered['id'] ?? null) && is_int($remembered['until'] ?? null)
            && $remembered['until'] >= $this->clock->now()->getTimestamp()
            ? $this->invitations->findOpen($remembered['id'])
            : null;
        if ($invitation === null) {
            $session->remove(self::SESSION);

            return $this->redirect(InvitationCodeHandler::PATH);
        }
        $problem = $this->emailPolicy->problem($invitation->email);
        if ($problem !== null) {
            return $this->pages->page($request, '@people/accept-invalid.html.twig', ['title' => 'This invitation can’t be used', 'reason' => $problem], 403);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, $invitation, self::form($invitation, $this->site->defaultTimezone, $this->passwords->hint()));
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(self::form($invitation, $this->site->defaultTimezone, $this->passwords->hint()), is_array($body) ? $body : []);
        $weak = $form->error('password') === null ? $this->passwords->problem($form->text('password'), $invitation->email) : null;
        if ($weak !== null) {
            $form = $form->withErrors(['password' => $weak]);
        }
        if (!$form->isValid()) {
            return $this->show($request, $invitation, $form, 422);
        }

        $display = $form->text('display_name');
        try {
            $account = $this->accounts->register(
                $invitation->email,
                $form->text('first_name'),
                $form->text('last_name'),
                $display === '' ? null : $display,
                $form->text('timezone'),
                $this->passwords->hash($form->text('password')),
            );
        } catch (AccountException $exception) {
            return $this->pages->page($request, '@people/accept-invalid.html.twig', ['title' => 'This invitation can’t be used', 'reason' => $exception->text()], 409);
        }
        $this->roles->assign((int) $account->id(), $invitation->role);
        $this->invitations->accept($invitation);
        $session->remove(self::SESSION);
        Flash::add($session, new Message('Your account is ready. Sign in with {email} and your new password.', ['email' => $invitation->email]));

        return $this->redirect('/login');
    }

    private function redirect(string $path): ResponseInterface
    {
        return $this->responses->createResponse(303)
            ->withHeader('Location', $path)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function show(ServerRequestInterface $request, Invitation $invitation, Form $form, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, '@people/accept.html.twig', [
            'title' => new Message('Join {name}', ['name' => $this->site->name]),
            'form' => $form,
            'invitation' => $invitation,
            'role' => $this->roles->find($invitation->role)->label ?? $invitation->role,
            'action' => self::PATH,
        ], $status);
    }

}
