<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\PasswordPolicy;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Auth\PasswordResetTokens;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\User\Contract\Account;

/**
 * `/password/reset/{token}`: the page a password link opens. It asks for the new password (or the first one, for a
 * pending account), sets it and sends the person to sign in. A link that expired, was used or was changed gets a
 * page that explains it and offers a new one.
 */
final readonly class ResetPasswordHandler implements RequestHandlerInterface
{
    public const string TOKEN_ATTRIBUTE = 'token';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private PasswordResetTokens $tokens,
        private PasswordReset $reset,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'reset-password',
            new Field(
                'password',
                'New password',
                FieldType::Password,
                hint: sprintf(
                    'At least %d characters. A few unrelated words make a strong password that is easy to remember.',
                    PasswordPolicy::MIN_LENGTH,
                ),
                autocomplete: 'new-password',
            ),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getAttribute(self::TOKEN_ATTRIBUTE);
        $account = is_string($token) ? $this->tokens->verify($token) : null;
        if ($account === null) {
            return $this->pages->page($request, 'reset-invalid.html.twig', [], 410);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form(), $account);
        }

        $form = $this->validator->submit(self::form(), AuthPages::body($request));
        $problem = $form->isValid() ? PasswordPolicy::problem($form->text('password'), $account->email()) : null;
        if ($problem !== null) {
            $form = $form->withErrors(['password' => $problem]);
        }
        if (!$form->isValid()) {
            return $this->show($request, $form, $account, 422);
        }

        $first = $account->isPending();
        $this->reset->complete($account, $form->text('password'));
        Flash::add(Session::fromRequest($request), $first
            ? 'Your password has been set. Sign in with it now.'
            : 'Your password has been changed. Sign in with your new password.');

        return $this->pages->redirect('/login');
    }

    private function show(ServerRequestInterface $request, Form $form, Account $account, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'reset.html.twig', [
            'form' => $form,
            'email' => $account->email(),
            'first' => $account->isPending(),
            'action' => $request->getUri()->getPath(),
        ], $status);
    }
}
