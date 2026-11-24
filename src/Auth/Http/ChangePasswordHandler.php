<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\User\Contract\Account;
use SightPress\Module\Event\AccountChanged;
use SightPress\Core\User\Contract\Accounts;

/**
 * `/account/password` — "Change your password", from *My account*. The current password is needed (wrong tries count
 * towards the sign-in limit); the new one must meet the site's policy and differ from it. Afterwards this browser
 * stays signed in (with a new session id) and every other session and "keep me signed in" device is signed out. The
 * change is audited, never the password.
 */
final readonly class ChangePasswordHandler implements RequestHandlerInterface
{
    public const string PATH = '/account/password';

    public const string WRONG = 'Your current password is not right. Check it and try again.';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private Accounts $accounts,
        private SignIn $signIn,
        private Passwords $passwords,
        private RememberTokens $rememberTokens,
        private ClockInterface $clock,
        private EventDispatcherInterface $events,
    ) {
    }

    public static function form(Passwords $passwords): Form
    {
        return Form::create(
            'change-password',
            new Field('current_password', 'Current password', FieldType::Password, autocomplete: 'current-password'),
            new Field('new_password', 'New password', FieldType::Password, hint: $passwords->hint(), autocomplete: 'new-password'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $account = AuthPages::signedIn($request, $this->accounts);
        if ($account === null) {
            return $this->pages->signInFirst(self::PATH);
        }
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form($this->passwords), $account);
        }

        $form = $this->validator->submit(self::form($this->passwords), AuthPages::body($request));
        if ($form->isValid()) {
            $form = $form->withErrors(array_filter(['new_password' => $form->text('new_password') === $form->text('current_password')
                ? 'Choose a new password that is different from your current one.'
                : $this->passwords->problem($form->text('new_password'), $account->email())], static fn (string|\SightPress\Core\Template\Message|null $problem): bool => $problem !== null));
        }
        if ($form->isValid()) {
            $check = $this->signIn->confirmPassword($request, $account, $form->text('current_password'));
            if (!$check->succeeded()) {
                $form = $form->withErrors(['current_password' => $check->retryAfter !== null ? AuthPages::tooManyAttempts('typed your password', $check->retryAfter) : self::WRONG]);
            }
        }
        if (!$form->isValid()) {
            return $this->show($request, $form, $account, 422);
        }

        $account->setPasswordHash($this->passwords->hash($form->text('new_password')), $this->clock->now());
        $this->accounts->saveAccount($account);
        $this->rememberTokens->forgetUser((int) $account->id());
        $this->signIn->start($request, $account);
        $this->events->dispatch(new AccountChanged((int) $account->id(), $account->id(), ['password']));
        Flash::add(Session::fromRequest($request), 'Your password has been changed. You are still signed in here, and signed out everywhere else.');

        $response = $this->pages->redirect('/account');

        return RememberTokens::hasCookie($request) ? $response->withAddedHeader('Set-Cookie', $this->rememberTokens->forget($request)) : $response;
    }

    private function show(ServerRequestInterface $request, Form $form, Account $account, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'account-password.html.twig', [
            'title' => 'Change your password',
            'form' => $form,
            'email' => $account->email(),
            'action' => self::PATH,
        ], $status);
    }
}
