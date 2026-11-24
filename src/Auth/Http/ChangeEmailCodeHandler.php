<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Account\EmailChange;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\Message;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\Contract\Accounts;

/**
 * `/account/email/code` — "Confirm your new email address": the person types the code we emailed to the new address.
 * A right code moves the account there (the old address gets a notice without a link) and goes back to *My account*.
 * "Send a new code" works at most once a minute. The form never sends itself.
 */
final readonly class ChangeEmailCodeHandler implements RequestHandlerInterface
{
    public const string PATH = '/account/email/code';

    public const string WRONG = 'That code is not right, or it no longer works. Check it and try again, or send a new code.';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private Accounts $accounts,
        private EmailChange $change,
        private EvenTiming $timing,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function form(string $email): Form
    {
        return Form::create('change-email-code', CodeEntry::field(new Message('We sent it by email to {email}. You can type it with or without spaces.', ['email' => VerificationTarget::mask(VerificationChannel::Email, $email)])));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $account = AuthPages::signedIn($request, $this->accounts);
        if ($account === null) {
            return $this->pages->signInFirst(ChangeEmailHandler::PATH);
        }
        $session = Session::fromRequest($request);
        $email = CodeSession::pending($session, VerificationPurpose::EmailChange);
        if ($email === null) {
            return $this->pages->redirect(ChangeEmailHandler::PATH);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form($email));
        }

        $body = AuthPages::body($request);
        if (($body['resend'] ?? null) !== null) {
            $limited = $this->timing->run(function () use ($request, $account, $email): ?TooManyCodes {
                try {
                    $this->change->request($request, $account, $email);

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

        $form = $this->validator->submit(self::form($email), $body);
        if (!$form->isValid()) {
            return $this->show($request, $form, 422);
        }
        try {
            $changed = $this->change->confirm($request, $account, $email, $form->text(CodeEntry::FIELD));
        } catch (TooManyCodes $exception) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => AuthPages::tooManyAttempts('tried a code', $exception->retryAfter)]), 429);
        }
        if (!$changed) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => self::WRONG]), 422);
        }

        CodeSession::clear($session);
        $this->events->dispatch(new AccountChanged((int) $account->id(), $account->id(), ['email']));
        Flash::add($session, new Message('Your email address is now {email}. Sign in with it from now on.', ['email' => $account->email()]));

        return $this->pages->redirect('/account');
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'account-email-code.html.twig', [
            'title' => 'Confirm your new email address',
            'form' => $form,
            'action' => self::PATH,
            'valid_for' => CodeEntry::duration(VerificationPurpose::EmailChange->lifetime(VerificationChannel::Email)),
        ], $status);
    }
}
