<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

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
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountException;
use SightPress\Core\User\Contract\AccountRegistration;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/invite/{token}`: the person who was invited sets up their account. They give their names (prefilled with what
 * the inviter knew), an optional display name, their time zone (prefilled from the browser when it can say) and a
 * password; the account gets its avatar and the role of the invitation, and they are asked to sign in.
 */
final readonly class AcceptInvitationHandler implements RequestHandlerInterface
{
    public const string TOKEN_ATTRIBUTE = 'token';

    public function __construct(
        private TemplateRenderer $templates,
        private ResponseFactoryInterface $responses,
        private FormValidator $validator,
        private Invitations $invitations,
        private AccountRegistration $accounts,
        private RoleGrants $roles,
        private Passwords $passwords,
        private EmailPolicy $emailPolicy,
        private Site $site,
    ) {
    }

    public static function form(Invitation $invitation, string $timezone): Form
    {
        $length = static fn (string $label): Length => new Length(max: 100, maxMessage: sprintf('%s must be 100 characters or fewer', $label));

        return Form::create(
            'accept',
            new Field('first_name', 'First name', autocomplete: 'given-name', constraints: [$length('First name')]),
            new Field('last_name', 'Last name', autocomplete: 'family-name', constraints: [$length('Last name')]),
            new Field('display_name', 'Display name', hint: 'Shown next to your work and in emails. Your first name is shown if you leave it empty.', required: false, constraints: [$length('Display name')], autocomplete: 'nickname'),
            new Field('timezone', 'Time zone', FieldType::Select, hint: 'Dates and times are shown in this time zone.', choices: TimezoneChoices::grouped()),
            new Field('password', 'Password', FieldType::Password, hint: 'At least 12 characters. A few unrelated words are easy to remember and hard to guess.', autocomplete: 'new-password'),
        )->withValues([
            'first_name' => $invitation->firstName ?? '',
            'last_name' => $invitation->lastName ?? '',
            'timezone' => $timezone,
        ]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getAttribute(self::TOKEN_ATTRIBUTE);
        $invitation = is_string($token) ? $this->invitations->find($token) : null;
        if ($invitation === null) {
            return $this->page($request, '@people/accept-invalid.html.twig', ['title' => 'This invitation no longer works'], 410);
        }
        $problem = $this->emailPolicy->problem($invitation->email);
        if ($problem !== null) {
            return $this->page($request, '@people/accept-invalid.html.twig', ['title' => 'This invitation can’t be used', 'reason' => $problem], 403);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, $invitation, self::form($invitation, $this->site->defaultTimezone));
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(self::form($invitation, $this->site->defaultTimezone), is_array($body) ? $body : []);
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
            return $this->page($request, '@people/accept-invalid.html.twig', ['title' => 'This invitation can’t be used', 'reason' => $exception->getMessage()], 409);
        }
        $this->roles->assign((int) $account->id(), $invitation->role);
        $this->invitations->accept($invitation);
        Flash::add(Session::fromRequest($request), sprintf('Your account is ready. Sign in with %s and your new password.', $invitation->email));

        return $this->responses->createResponse(303)
            ->withHeader('Location', '/login')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function show(ServerRequestInterface $request, Invitation $invitation, Form $form, int $status = 200): ResponseInterface
    {
        return $this->page($request, '@people/accept.html.twig', [
            'title' => sprintf('Join %s', $this->site->name),
            'form' => $form,
            'invitation' => $invitation,
            'role' => $this->roles->find($invitation->role)->label ?? $invitation->role,
            'action' => $request->getUri()->getPath(),
        ], $status);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(ServerRequestInterface $request, string $template, array $context, int $status): ResponseInterface
    {
        return $this->templates
            ->response($request, $template, $context + [
                'site_name' => $this->site->name,
                'messages' => Flash::take(Session::fromRequest($request)),
            ], $status)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
