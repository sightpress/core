<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Auth\Registration\SelfRegistration;
use SightPress\Core\Template\Message;

/**
 * `/register/code` — "Confirm your email address": the person who signed up types the code we emailed them. A right
 * code makes the account usable and asks them to sign in (then back to the page they came from). "Send a new code"
 * emails another one, at most once a minute, and says how long to wait in words. The form never sends itself.
 * Someone who signed up earlier and tries to sign in is sent here too, so this page works even after the site stops
 * letting people sign up.
 */
final readonly class RegisterCodeHandler implements RequestHandlerInterface
{
    public const string PATH = SignUp::CODE_PATH;

    public const string WRONG = 'That code is not right, or it no longer works. Check it and try again, or send a new code.';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private SelfRegistration $registration,
        private EvenTiming $timing,
    ) {
    }

    public static function form(string $email): Form
    {
        return Form::create('register-code', CodeEntry::field(new Message('We sent it by email to {email}. You can type it with or without spaces.', ['email' => VerificationTarget::mask(VerificationChannel::Email, $email)])));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = Session::fromRequest($request);
        $email = CodeSession::pending($session, VerificationPurpose::EmailVerify);
        if ($email === null) {
            return $this->pages->redirect($this->registration->isOpen() ? RegisterHandler::PATH : '/login');
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form($email));
        }

        $body = AuthPages::body($request);
        if (($body['resend'] ?? null) !== null) {
            return $this->resend($request, $session, $email);
        }
        $form = $this->validator->submit(self::form($email), $body);
        if (!$form->isValid()) {
            return $this->show($request, $form, 422);
        }
        try {
            $account = $this->registration->confirm($request, $email, $form->text(CodeEntry::FIELD));
        } catch (TooManyCodes $exception) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => AuthPages::tooManyAttempts('tried a code', $exception->retryAfter)]), 429);
        }
        if ($account === null) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => self::WRONG]), 422);
        }

        $return = $session->get(RegisterHandler::RETURN_SESSION);
        CodeSession::clear($session);
        $session->remove(RegisterHandler::RETURN_SESSION);
        $session->regenerate();
        Flash::add($session, new Message('Your email address is confirmed and your account is ready. Sign in with {email} and your password.', ['email' => $account->email()]));

        return $this->pages->redirect('/login' . (is_string($return) ? '?' . http_build_query(['return' => $return]) : ''));
    }

    private function resend(ServerRequestInterface $request, Session $session, string $email): ResponseInterface
    {
        $limited = $this->timing->run(function () use ($request, $email): ?TooManyCodes {
            try {
                $this->registration->resend($request, $email);

                return null;
            } catch (TooManyCodes $exception) {
                return $exception;
            }
        });
        if ($limited !== null) {
            return $this->show($request, self::form($email)->withErrors([CodeEntry::FIELD => $limited->tooSoon
                ? new Message('Wait {retry_after}, then send a new code. The code we sent before still works.', ['retry_after' => CodeEntry::wait($limited->retryAfter)])
                : AuthPages::tooManyAttempts('asked for a code', $limited->retryAfter)]), 429);
        }
        Flash::add($session, new Message('We have sent a new code to {email}. The code we sent before no longer works.', ['email' => VerificationTarget::mask(VerificationChannel::Email, $email)]));

        return $this->pages->redirect(self::PATH);
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'register-code.html.twig', [
            'title' => new Message('Confirm your email address'),
            'form' => $form,
            'action' => self::PATH,
            'valid_for' => CodeEntry::duration(VerificationPurpose::EmailVerify->lifetime(VerificationChannel::Email)),
            'start_again' => $this->registration->isOpen() ? RegisterHandler::PATH : null,
        ], $status);
    }

}
