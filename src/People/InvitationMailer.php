<?php

declare(strict_types=1);

namespace SightPress\Core\People;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\IssuedCode;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the invitation email, as HTML and plain text with the same content: who invited them, the site (by its
 * name, never its address), the role, the invitation code, until when it works (in the site's time zone, which the
 * email names) and where to type it: "Accept an invitation" on the sign-in page. No link, no web address, no image;
 * the code is never in the subject.
 */
final readonly class InvitationMailer
{
    public function __construct(
        private MailSender $mail,
        private TemplateRenderer $templates,
        private Site $site,
    ) {
    }

    public function send(ServerRequestInterface $request, Invitation $invitation, IssuedCode $code, string $inviter, string $roleLabel): void
    {
        $expires = $invitation->expiresAt->setTimezone(new \DateTimeZone($this->site->defaultTimezone));
        $context = [
            'site_name' => $this->site->name,
            'inviter' => $inviter,
            'role' => $roleLabel,
            'name' => $invitation->firstName,
            'code' => $code->readable(),
            'valid_for' => CodeEntry::duration(VerificationPurpose::Invitation->lifetime(VerificationChannel::Email)),
            'expires' => sprintf('%s (%s time)', $expires->format('j F Y, H:i'), $this->site->defaultTimezone),
        ];

        $this->mail->send(new Email()
            ->to(new Address($invitation->email))
            ->subject(sprintf('%s invited you to %s', $inviter, $this->site->name))
            ->text($this->templates->render($request, '@people/email/invitation.txt.twig', $context))
            ->html($this->templates->render($request, '@people/email/invitation.html.twig', $context)));
    }
}
