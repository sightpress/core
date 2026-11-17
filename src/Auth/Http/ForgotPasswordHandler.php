<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\EvenTiming;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;

/**
 * `/login/forgot`: asks for an email address, emails a six-digit code and moves on to the page where it is typed
 * ({@see ResetCodeHandler}). The answer, and how long it takes, is the same whether or not the address has an
 * account.
 */
final readonly class ForgotPasswordHandler implements RequestHandlerInterface
{
    public const string PATH = '/login/forgot';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private PasswordReset $reset,
        private EvenTiming $timing,
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

        $email = mb_strtolower($form->text('email'));
        $limited = $this->timing->run(function () use ($request, $email): ?TooManyCodes {
            try {
                $this->reset->request($request, $email);

                return null;
            } catch (TooManyCodes $exception) {
                return $exception;
            }
        });
        if ($limited !== null && !$limited->tooSoon) {
            return $this->pages->page($request, 'forgot.html.twig', [
                'form' => $form->withErrors(['email' => AuthPages::tooManyAttempts('asked for a code', $limited->retryAfter)]),
            ], 429);
        }

        CodeSession::startPending(Session::fromRequest($request), VerificationPurpose::PasswordReset, $email);

        return $this->pages->redirect(ResetCodeHandler::PATH);
    }
}
