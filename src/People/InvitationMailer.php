<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the invitation email, as HTML and plain text with the same content: who invited them, the site, the role,
 * the link and until when it works (in the site's time zone, which the email names).
 */
final readonly class InvitationMailer
{
    public const string PATH = '/invite/';

    public function __construct(
        private MailSender $mail,
        private TemplateRenderer $templates,
        private Site $site,
    ) {
    }

    public function send(ServerRequestInterface $request, Invitation $invitation, string $token, string $inviter, string $roleLabel): void
    {
        $uri = $request->getUri();
        $port = $uri->getPort() === null ? '' : ':' . $uri->getPort();
        $expires = $invitation->expiresAt->setTimezone(new \DateTimeZone($this->site->defaultTimezone));
        $context = [
            'site_name' => $this->site->name,
            'inviter' => $inviter,
            'role' => $roleLabel,
            'name' => $invitation->firstName,
            'link' => sprintf('%s://%s%s%s%s', $uri->getScheme(), $uri->getHost(), $port, self::PATH, $token),
            'expires' => sprintf('%s (%s time)', $expires->format('j F Y, H:i'), $this->site->defaultTimezone),
        ];

        $this->mail->send(new Email()
            ->to(new Address($invitation->email))
            ->subject(sprintf('%s invited you to %s', $inviter, $this->site->name))
            ->text($this->templates->render($request, '@people/email/invitation.txt.twig', $context))
            ->html($this->templates->render($request, '@people/email/invitation.html.twig', $context)));
    }
}
