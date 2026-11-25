<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Auth\PasswordChangedNotice;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;

/**
 * `/login/password`: after a code proved the account (reset or setup), the person chooses their new password — or
 * their first one, for a pending account — and is sent to sign in. Without a proved code, or once
 * {@see CodeSession::VERIFIED_FOR} has passed, it sends them back to ask for a code. The password must meet the
 * site's policy ({@see Passwords}), which the hint spells out.
 */
final readonly class ChoosePasswordHandler implements RequestHandlerInterface
{
    public const string PATH = '/login/password';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private PasswordReset $reset,
        private Accounts $accounts,
        private ClockInterface $clock,
        private Passwords $passwords,
        private PasswordChangedNotice $notice,
    ) {
    }

    /**
     * The form, with the site's password policy in the hint.
     */
    public static function form(Passwords $passwords): Form
    {
        return Form::create(
            'choose-password',
            new Field('password', 'New password', FieldType::Password, hint: $passwords->hint(), autocomplete: 'new-password'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = Session::fromRequest($request);
        $verified = CodeSession::verifiedAccount($session, $this->clock->now()->getTimestamp());
        $account = $verified === null ? null : $this->accounts->findAccount($verified);
        if ($verified === null || $account === null) {
            CodeSession::clear($session);
            Flash::add($session, 'Your code has run out of time. Ask for a new code to choose your password.');

            return $this->pages->redirect(ForgotPasswordHandler::PATH);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form($this->passwords), $account);
        }

        $form = $this->validator->submit(self::form($this->passwords), AuthPages::body($request));
        $problem = $form->isValid() ? $this->passwords->problem($form->text('password'), $account->email()) : null;
        if ($problem !== null) {
            $form = $form->withErrors(['password' => $problem]);
        }
        if (!$form->isValid()) {
            return $this->show($request, $form, $account, 422);
        }

        $first = $account->isPending();
        $this->reset->complete($account, $form->text('password'));
        if (!$first) {
            $this->notice->send($request, $account, $session->get(ResetCodeHandler::BY_TEXT) === true);
        }
        $session->remove(ResetCodeHandler::BY_TEXT);
        CodeSession::clear($session);
        Flash::add($session, $first
            ? 'Your password has been set. Sign in with it now.'
            : 'Your password has been changed. Sign in with your new password.');

        return $this->pages->redirect('/login');
    }

    private function show(ServerRequestInterface $request, Form $form, Account $account, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'choose-password.html.twig', [
            'form' => $form,
            'email' => $account->email(),
            'first' => $account->isPending(),
            'action' => self::PATH,
        ], $status);
    }
}
