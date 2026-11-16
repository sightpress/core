<?php

declare(strict_types=1);

namespace SightPress\Core\Contact\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Block\Contract\BlockFinder;
use SightPress\Core\Contact\ContactForm;
use SightPress\Core\Contact\ContactMailer;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Contract\PublicPages;
use Slim\Exception\HttpNotFoundException;

/**
 * `POST /contact-form`: a visitor sends a contact form. The form is found on the page it was sent from (published
 * and public), by its id; the recipient comes from the page, never from the request. Then:
 *
 * - more than the `contact_form` limit from one address: the form comes back with an error;
 * - the hidden field was filled in (a bot): it looks sent, but nothing is sent;
 * - fields missing or wrong: the form comes back with the errors and what was typed;
 * - otherwise the message is emailed and the page says it was sent.
 *
 * Every answer is a 303 back to the page (Post/Redirect/Get). There, focus moves to the error summary or the
 * status message (`autofocus`), which also scrolls to it. The address has no fragment on purpose: browsers ignore
 * `autofocus` when the address points at an element.
 */
final readonly class ContactFormHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublicPages $pages,
        private BlockFinder $blocks,
        private FormValidator $validator,
        private RateLimiters $limiters,
        private ContactMailer $mailer,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $return = is_string($body['return'] ?? null) ? $body['return'] : '';
        $id = is_string($body['form'] ?? null) ? $body['form'] : '';
        $page = $this->pages->findByUrl($return);
        $data = null;
        foreach ($page === null ? [] : $this->blocks->find($page->blocks, 'contact_form') as $candidate) {
            if (ContactForm::id($candidate) === $id) {
                $data = $candidate;
            }
        }
        if ($page === null || $data === null) {
            throw new HttpNotFoundException($request, 'This form isn\'t on the page any more. Go back, reload the page and send your message again.');
        }

        $session = Session::fromRequest($request);
        $form = $this->validator->submit(ContactForm::form($id), $body);
        /** @var array<string, string> $values */
        $values = $form->values();
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $limit = $this->limiters->limiter('contact_form', is_string($address) ? $address : 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $minutes = (int) ceil(RateLimiters::secondsUntil($limit->getRetryAfter()) / 60);
            ContactForm::remember($session, $id, $values, ['message' => sprintf(
                'You have sent a lot of messages. Try again in %d %s.',
                $minutes,
                $minutes === 1 ? 'minute' : 'minutes',
            )], false);
        } elseif (is_string($body[ContactForm::HONEYPOT] ?? null) && $body[ContactForm::HONEYPOT] !== '') {
            ContactForm::remember($session, $id, [], [], true);
        } elseif (!$form->isValid()) {
            ContactForm::remember($session, $id, $values, $form->errors(), false);
        } else {
            $this->mailer->send($request, $page, $data, $form->text('name'), $form->text('email'), $form->text('message'));
            ContactForm::remember($session, $id, [], [], true);
        }

        return $this->responses->createResponse(303)
            ->withHeader('Location', $page->url)
            ->withHeader('Cache-Control', 'no-store');
    }
}
