<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Auth\ReturnTarget;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Form\TimezoneChoices;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Auth\Registration\RegistrationDetails;
use SightPress\Core\Auth\Registration\SelfRegistration;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/register` — "Create an account", while the site lets anyone sign up (404 otherwise). The visitor gives their
 * names, an optional display name, their email address, their time zone (prefilled from the browser when it can say),
 * their language on a site written in several, and a password that meets the site's policy. Then they type the code
 * we emailed them ({@see RegisterCodeHandler}).
 *
 * Spam: a field hidden from everyone ("Leave this field empty") that only bots fill in — they get the same answer and
 * nothing is made — and the `registration` rate limit per client address. No image puzzles. An address that already
 * has an account gets exactly the same answer (its owner gets a notice). A role sent with the form is never read.
 */
final readonly class RegisterHandler implements RequestHandlerInterface
{
    public const string PATH = SignUp::PATH;

    /** The hidden field only bots fill in. */
    public const string HONEYPOT = 'website';

    /** Where to go back to after signing in, kept while the person types their code. */
    public const string RETURN_SESSION = 'people.registration.return';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private SelfRegistration $registration,
        private Passwords $passwords,
        private EmailPolicy $emailPolicy,
        private RateLimiters $limiters,
        private EvenTiming $timing,
        private Site $site,
    ) {
    }

    /**
     * @param list<string> $locales the site's languages; the field is only there when there are several
     */
    public static function form(array $locales, string $locale, string $timezone, string|Message $passwordHint): Form
    {
        $length = static fn (): Length => new Length(max: 100, maxMessage: '{field} must be {{ limit }} characters or fewer');
        $fields = [
            new Field('first_name', 'First name', autocomplete: 'given-name', constraints: [$length()]),
            new Field('last_name', 'Last name', autocomplete: 'family-name', constraints: [$length()]),
            new Field('display_name', 'Display name', hint: 'Shown next to your name on the site and in emails. Your first name is shown if you leave it empty.', required: false, constraints: [$length()], autocomplete: 'nickname'),
            new Field('email', 'Email address', FieldType::Email, hint: 'We email you a code to check it is yours. You sign in with it.', autocomplete: 'email', constraints: [new Length(max: 254, maxMessage: '{field} must be {{ limit }} characters or fewer')]),
            new Field('timezone', 'Time zone', FieldType::Select, hint: 'Dates and times are shown in this time zone.', choices: TimezoneChoices::grouped()),
        ];
        if (count($locales) > 1) {
            $choices = [];
            foreach ($locales as $code) {
                $choices[$code] = LocaleCode::ownName($code);
            }
            $fields[] = new Field('locale', 'Language', FieldType::Select, hint: 'Emails from the site are written in this language.', choices: $choices, localeChoices: true);
        }
        $fields[] = new Field('password', 'Password', FieldType::Password, hint: $passwordHint, autocomplete: 'new-password');

        return Form::create('register', ...$fields)->withValues(['timezone' => $timezone, 'locale' => $locale]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->registration->isOpen()) {
            throw new HttpNotFoundException($request);
        }
        $return = ReturnTarget::localPath(AuthPages::query($request, 'return'));
        $actor = Actor::fromRequest($request);
        if ($actor->isSignedIn()) {
            return $this->pages->redirect(ReturnTarget::for($actor, $return));
        }
        $viewer = Viewer::fromRequest($request, new Viewer($this->site->defaultLocale, $this->site->defaultTimezone));
        $locale = in_array($viewer->locale, $this->site->locales, true) ? $viewer->locale : $this->site->defaultLocale;
        $form = self::form($this->site->locales, $locale, $this->site->defaultTimezone, $this->passwords->hint());
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, $form, $return);
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $limit = $this->limiters->limiter('registration', self::client($request))->consume();
        if (!$limit->isAccepted()) {
            $minutes = (int) ceil(RateLimiters::secondsUntil($limit->getRetryAfter()) / 60);

            return $this->show($request, $this->validator->submit($form, $body)->withErrors(['email' => new Message('Too many accounts have been made from here. Wait {minutes, plural, one {# minute} other {# minutes}} and try again.', ['minutes' => $minutes])]), $return, 429);
        }
        $form = $this->validator->submit($form, $body);
        $email = mb_strtolower(trim($form->text('email')));
        $problem = $form->error('email') === null ? $this->emailPolicy->problem($email) : null;
        if ($problem !== null) {
            $form = $form->withErrors(['email' => $problem]);
        }
        $weak = $form->error('password') === null ? $this->passwords->problem($form->text('password'), $email) : null;
        if ($weak !== null) {
            $form = $form->withErrors(['password' => $weak]);
        }
        if (!$form->isValid()) {
            return $this->show($request, $form, $return, 422);
        }

        $session = Session::fromRequest($request);
        if (!is_string($body[self::HONEYPOT] ?? null) || $body[self::HONEYPOT] === '') {
            $display = $form->text('display_name');
            $details = new RegistrationDetails(
                $email,
                $form->text('first_name'),
                $form->text('last_name'),
                $display === '' ? null : $display,
                $form->text('timezone'),
                $form->has('locale') ? $form->text('locale') : null,
                $form->text('password'),
            );
            $limited = $this->timing->run(function () use ($request, $details): ?TooManyCodes {
                try {
                    $this->registration->start($request, $details);

                    return null;
                } catch (TooManyCodes $exception) {
                    return $exception;
                }
            });
            if ($limited !== null && !$limited->tooSoon) {
                return $this->show($request, $form->withErrors(['email' => AuthPages::tooManyAttempts('asked for a code', $limited->retryAfter)]), $return, 429);
            }
        }

        CodeSession::startPending($session, VerificationPurpose::EmailVerify, $email);
        if ($return === null) {
            $session->remove(self::RETURN_SESSION);
        } else {
            $session->set(self::RETURN_SESSION, $return);
        }

        return $this->pages->redirect(SignUp::CODE_PATH);
    }

    private function show(ServerRequestInterface $request, Form $form, ?string $return, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'register.html.twig', [
            'title' => new Message('Create an account on {site}', ['site' => $this->site->name]),
            'form' => $form,
            'honeypot' => self::HONEYPOT,
            'action' => self::PATH . ($return === null ? '' : '?' . http_build_query(['return' => $return])),
            'sign_in' => '/login' . ($return === null ? '' : '?' . http_build_query(['return' => $return])),
        ], $status);
    }


    private static function client(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : 'unknown';
    }
}
