<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use SightPress\Core\Template\Message;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;

/**
 * `/login/forgot/code`: the person types the code we emailed them. A right code lets them choose a new password
 * ({@see ChoosePasswordHandler}); "Send a new code" emails another one, at most once a minute, and says how long to
 * wait in words. The form never sends itself: the person presses Continue.
 */
final readonly class ResetCodeHandler implements RequestHandlerInterface
{
    public const string PATH = '/login/forgot/code';

    public const string WRONG = 'That code is not right, or it no longer works. Check it and try again, or send a new code.';

    /** Session key: the proved code came by text, for the email that tells the person about the change. */
    public const string BY_TEXT = 'auth.reset.by_text';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private PasswordReset $reset,
        private EvenTiming $timing,
        private ClockInterface $clock,
    ) {
    }

    public static function form(string $email): Form
    {
        return Form::create('reset-code', CodeEntry::field(PasswordReset::isPhone($email)
            ? new Message('We sent it by text message to {phone}. You can type it with or without spaces.', ['phone' => VerificationTarget::mask(VerificationChannel::Sms, $email)])
            : new Message('We sent it by email to {email}. You can type it with or without spaces.', ['email' => VerificationTarget::mask(VerificationChannel::Email, $email)])));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = Session::fromRequest($request);
        $email = CodeSession::pending($session, VerificationPurpose::PasswordReset);
        if ($email === null) {
            return $this->pages->redirect(ForgotPasswordHandler::PATH);
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
            $account = $this->reset->verify($request, VerificationPurpose::PasswordReset, $email, $form->text(CodeEntry::FIELD));
        } catch (TooManyCodes $exception) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => AuthPages::tooManyAttempts('tried a code', $exception->retryAfter)]), 429);
        }
        if ($account === null) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => self::WRONG]), 422);
        }

        CodeSession::verified($session, VerificationPurpose::PasswordReset, (int) $account->id(), $this->clock->now()->getTimestamp());
        $session->set(self::BY_TEXT, PasswordReset::isPhone($email));

        return $this->pages->redirect(ChoosePasswordHandler::PATH);
    }

    private function resend(ServerRequestInterface $request, Session $session, string $email): ResponseInterface
    {
        $limited = $this->timing->run(function () use ($request, $email): ?TooManyCodes {
            try {
                $this->reset->request($request, $email);

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

        Flash::add($session, PasswordReset::isPhone($email)
            ? new Message('We have sent a new code to {phone}. The code we sent before no longer works.', ['phone' => VerificationTarget::mask(VerificationChannel::Sms, $email)])
            : new Message('We have sent a new code to {email}. The code we sent before no longer works.', ['email' => VerificationTarget::mask(VerificationChannel::Email, $email)]));

        return $this->pages->redirect(self::PATH);
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        $byText = PasswordReset::isPhone(CodeSession::pending(Session::fromRequest($request), VerificationPurpose::PasswordReset) ?? '');

        return $this->pages->page($request, 'reset-code.html.twig', [
            'form' => $form,
            'action' => self::PATH,
            'by_text' => $byText,
            'valid_for' => CodeEntry::duration(VerificationPurpose::PasswordReset->lifetime($byText ? VerificationChannel::Sms : VerificationChannel::Email)),
        ], $status);
    }
}
