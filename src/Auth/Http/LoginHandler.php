<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\DomainRegistration;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\ReturnTarget;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\PermissionLookup;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;

/**
 * `/login`, shared by every role: the sign-in form, and signing in. Afterwards people go back to the page they came
 * from (`?return=`, or the referring page of this site), never to `/admin` without `admin.access`. "Keep me signed
 * in" is unticked by default. While the site lets people sign up, the page links to the sign-up form ("Register with
 * your organisation email" when only people at the organisation's domains may, X-7); someone who
 * signed up but hasn't confirmed their email address goes to the page where they type the code.
 */
final readonly class LoginHandler implements RequestHandlerInterface
{
    public const string WRONG = 'Enter a correct email address and password.';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private SignIn $signIn,
        private PermissionLookup $permissions,
        private RememberTokens $rememberTokens,
        private SiteSettings $settings,
        private DomainRegistration $domainRegistration,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'sign-in',
            new Field('email', 'Email address', FieldType::Email, autocomplete: 'username'),
            new Field('password', 'Password', FieldType::Password, autocomplete: 'current-password'),
            new Field(
                'remember',
                'Keep me signed in on this device for 30 days',
                FieldType::Checkbox,
                hint: 'Only tick this on a device that only you use.',
                required: false,
            ),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $return = AuthPages::query($request, 'return') ?? self::referrer($request);
        $actor = Actor::fromRequest($request);
        if ($actor->isSignedIn()) {
            return $this->pages->redirect(ReturnTarget::for($actor, $return));
        }

        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form(), $return);
        }

        $form = $this->validator->submit(self::form(), AuthPages::body($request));
        if (!$form->isValid()) {
            return $this->show($request, $form, $return, 422);
        }

        $result = $this->signIn->attempt($request, $form->text('email'), $form->text('password'));
        if ($result->retryAfter !== null) {
            return $this->show($request, $form->withErrors([
                'email' => AuthPages::tooManyAttempts('tried to sign in', $result->retryAfter),
            ]), $return, 429);
        }
        if ($result->unconfirmedEmail !== null) {
            $session = Session::fromRequest($request);
            CodeSession::startPending($session, VerificationPurpose::EmailVerify, $result->unconfirmedEmail);
            Flash::add($session, 'You haven’t confirmed your email address yet. Type the code we emailed you, or send a new one.');

            return $this->pages->redirect(SignUp::CODE_PATH);
        }
        if ($result->account === null) {
            return $this->show($request, $form->withErrors(['email' => self::WRONG]), $return, 422);
        }

        $this->signIn->start($request, $result->account);
        $id = (int) $result->account->id();
        $response = $this->pages->redirect(
            ReturnTarget::for(new Actor($id, $this->permissions->permissionNamesOf($id)), $return),
        );

        return $form->value('remember') === '1'
            ? $response->withAddedHeader(
                'Set-Cookie',
                $this->rememberTokens->issue($id, $request->getUri()->getScheme() === 'https'),
            )
            : $response;
    }

    /**
     * The page of this site the person came from, when the browser says so and there is no `?return=`.
     */
    private static function referrer(ServerRequestInterface $request): ?string
    {
        if ($request->getMethod() === 'POST') {
            return null;
        }
        $referrer = parse_url($request->getHeaderLine('Referer'));
        if (!is_array($referrer) || ($referrer['host'] ?? null) !== $request->getUri()->getHost() || !isset($referrer['path'])) {
            return null;
        }

        return $referrer['path'] . (isset($referrer['query']) ? '?' . $referrer['query'] : '');
    }

    private function show(ServerRequestInterface $request, Form $form, ?string $return, int $status = 200): ResponseInterface
    {
        $local = ReturnTarget::localPath($return);
        $open = SignUp::isOpen($this->settings->get(SignUp::SETTING));
        $signUp = SignUp::PATH . ($local === null ? '' : '?' . http_build_query(['return' => $local]));

        return $this->pages->page($request, 'login.html.twig', [
            'form' => $form,
            'action' => '/login' . ($local === null ? '' : '?' . http_build_query(['return' => $local])),
            'members_only' => SiteVisibility::isMembersOnly($this->settings),
            'sign_up' => $open ? $signUp : null,
            'domain_sign_up' => !$open && $this->domainRegistration->domains() !== null ? $signUp : null,
        ], $status);
    }
}
