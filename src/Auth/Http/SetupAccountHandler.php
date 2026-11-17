<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\CodeSession;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;

/**
 * `/login/setup`: the first admin of a new site (or an admin made with `site:user:create-admin`) types their email
 * address and the setup code the operator gave them, then chooses their password ({@see ChoosePasswordHandler}).
 */
final readonly class SetupAccountHandler implements RequestHandlerInterface
{
    public const string PATH = '/login/setup';

    public const string WRONG = 'That email address and code don’t match, or the code no longer works. Check them and try again.';

    public function __construct(
        private AuthPages $pages,
        private FormValidator $validator,
        private PasswordReset $reset,
        private ClockInterface $clock,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'setup-account',
            new Field('email', 'Email address', FieldType::Email, hint: 'The address your account was made with.', autocomplete: 'username'),
            CodeEntry::field('The setup code you were given. You can type it with or without spaces.'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form());
        }

        $form = $this->validator->submit(self::form(), AuthPages::body($request));
        if (!$form->isValid()) {
            return $this->show($request, $form, 422);
        }
        try {
            $account = $this->reset->verify($request, VerificationPurpose::AccountSetup, $form->text('email'), $form->text(CodeEntry::FIELD));
        } catch (TooManyCodes $exception) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => AuthPages::tooManyAttempts('tried a code', $exception->retryAfter)]), 429);
        }
        if ($account === null) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => self::WRONG]), 422);
        }

        CodeSession::verified(Session::fromRequest($request), VerificationPurpose::AccountSetup, (int) $account->id(), $this->clock->now()->getTimestamp());

        return $this->pages->redirect(ChoosePasswordHandler::PATH);
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, 'setup.html.twig', ['form' => $form, 'action' => self::PATH], $status);
    }
}
