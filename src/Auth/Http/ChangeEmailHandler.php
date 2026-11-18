<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

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
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Template\Message;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `/account/email` — "Change your email address", from *My account*: the new address and the current password. A
 * 6-digit code goes to the new address and the person types it on `/account/email/code`; the account keeps its old
 * address until then. The answer is the same whether or not the new address already has an account.
 */
final readonly class ChangeEmailHandler implements RequestHandlerInterface
{
    public const string PATH = '/account/email';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private Accounts $accounts,
        private SignIn $signIn,
        private EmailChange $change,
        private EvenTiming $timing,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'change-email',
            new Field('new_email', 'New email address', FieldType::Email, hint: 'We email a code to this address to check it is yours.', autocomplete: 'email', constraints: [new Length(max: 254, maxMessage: '{field} must be {{ limit }} characters or fewer')]),
            new Field('current_password', 'Current password', FieldType::Password, hint: 'So we know it is you.', autocomplete: 'current-password'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $account = AuthPages::signedIn($request, $this->accounts);
        if ($account === null) {
            return $this->pages->signInFirst(self::PATH);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form(), $account);
        }

        $form = $this->validator->submit(self::form(), AuthPages::body($request));
        if ($form->isValid()) {
            $form = $form->withErrors(array_filter(['new_email' => $this->change->problem($account, $form->text('new_email'))], static fn (string|\SightPress\Core\Template\Message|null $problem): bool => $problem !== null));
        }
        if ($form->isValid()) {
            $check = $this->signIn->confirmPassword($request, $account, $form->text('current_password'));
            if (!$check->succeeded()) {
                $form = $form->withErrors(['current_password' => $check->retryAfter !== null ? AuthPages::tooManyAttempts('typed your password', $check->retryAfter) : ChangePasswordHandler::WRONG]);
            }
        }
        if (!$form->isValid()) {
            return $this->show($request, $form, $account, 422);
        }

        $email = VerificationTarget::normalise(VerificationChannel::Email, $form->text('new_email'));
        $limited = $this->timing->run(function () use ($request, $account, $email): ?TooManyCodes {
            try {
                $this->change->request($request, $account, $email);

                return null;
            } catch (TooManyCodes $exception) {
                return $exception;
            }
        });
        if ($limited !== null) {
            return $this->show($request, $form->withErrors(['new_email' => $limited->tooSoon
                ? new Message('Wait {retry_after}, then ask again. The code we sent before still works.', ['retry_after' => CodeEntry::wait($limited->retryAfter)])
                : AuthPages::tooManyAttempts('asked for a code', $limited->retryAfter)]), $account, 429);
        }
        CodeSession::startPending(Session::fromRequest($request), VerificationPurpose::EmailChange, $email);

        return $this->pages->redirect(ChangeEmailCodeHandler::PATH);
    }

    private function show(ServerRequestInterface $request, Form $form, Account $account, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'account-email.html.twig', [
            'title' => 'Change your email address',
            'form' => $form,
            'email' => $account->email(),
            'action' => self::PATH,
        ], $status);
    }
}
