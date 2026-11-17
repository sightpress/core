<?php

declare(strict_types=1);

namespace SightPress\Core\People\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\People\Invitations;

/**
 * `/login/invitation` — "Accept an invitation": the invited person types their email address and the invitation
 * code from the email. A right code opens the form where they set up their account ({@see AcceptInvitationHandler}).
 * A wrong address and a wrong code get the same answer.
 */
final readonly class InvitationCodeHandler implements RequestHandlerInterface
{
    public const string PATH = '/login/invitation';

    public const string WRONG = 'That email address and code don’t match an invitation, or the code no longer works. Check them and try again.';

    public function __construct(
        private InvitationPages $pages,
        private FormValidator $validator,
        private Invitations $invitations,
        private ClockInterface $clock,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public static function form(): Form
    {
        return Form::create(
            'invitation-code',
            new Field('email', 'Email address', FieldType::Email, hint: 'The address the invitation was sent to.', autocomplete: 'email'),
            CodeEntry::field('The invitation code from the email. You can type it with or without spaces.'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->show($request, self::form());
        }

        $body = $request->getParsedBody();
        $form = $this->validator->submit(self::form(), is_array($body) ? $body : []);
        if (!$form->isValid()) {
            return $this->show($request, $form, 422);
        }
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        try {
            $invitation = $this->invitations->verify($form->text('email'), $form->text(CodeEntry::FIELD), is_string($address) ? $address : 'unknown');
        } catch (TooManyCodes $exception) {
            $minutes = (int) ceil($exception->retryAfter / 60);

            return $this->show($request, $form->withErrors([CodeEntry::FIELD => sprintf(
                'You have tried a code too many times. Wait %d %s and try again.',
                $minutes,
                $minutes === 1 ? 'minute' : 'minutes',
            )]), 429);
        }
        if ($invitation === null) {
            return $this->show($request, $form->withErrors([CodeEntry::FIELD => self::WRONG]), 422);
        }

        AcceptInvitationHandler::remember(Session::fromRequest($request), $invitation, $this->clock->now()->getTimestamp());

        return $this->responses->createResponse(303)
            ->withHeader('Location', AcceptInvitationHandler::PATH)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function show(ServerRequestInterface $request, Form $form, int $status = 200): ResponseInterface
    {
        return $this->pages->page($request, '@people/invitation-code.html.twig', [
            'title' => 'Accept an invitation',
            'form' => $form,
            'action' => self::PATH,
        ], $status);
    }
}
