<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;

/**
 * `/password/forgot`: asks for an email address and sends a link for choosing a new password. The answer is the
 * same whether or not the address has an account.
 */
final readonly class ForgotPasswordHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private PasswordReset $reset,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'forgot-password',
            new Field(
                'email',
                'Email address',
                FieldType::Email,
                hint: 'The address you sign in with.',
                autocomplete: 'username',
            ),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->pages->page($request, 'forgot.html.twig', ['form' => self::form()]);
        }

        $form = $this->validator->submit(self::form(), AuthPages::body($request));
        if (!$form->isValid()) {
            return $this->pages->page($request, 'forgot.html.twig', ['form' => $form], 422);
        }

        $retryAfter = $this->reset->request($request, $form->text('email'));
        if ($retryAfter !== null) {
            return $this->pages->page($request, 'forgot.html.twig', [
                'form' => $form->withErrors(['email' => AuthPages::tooManyAttempts('asked for a link', $retryAfter)]),
            ], 429);
        }

        return $this->pages->page($request, 'forgot-sent.html.twig', ['email' => $form->text('email')]);
    }
}
