<?php

declare(strict_types=1);

namespace SightPress\Core\Contact;

use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Message;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Page\Contract\PublicPage;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a contact form message to the form's recipient, with the visitor as the reply-to address, so answering is
 * just replying. The visitor's address is never the sender, so the site's mail isn't rejected as spoofed.
 */
final readonly class ContactMailer
{
    public function __construct(
        private MailSender $mail,
        private TemplateRenderer $templates,
        private Site $site,
        private Translations $translations,
    ) {
    }

    /**
     * @param array<string, mixed> $data the block's data
     */
    public function send(ServerRequestInterface $request, PublicPage $page, array $data, string $name, string $email, string $message): void
    {
        // Sent to the people who run the site: in the site's language.
        $viewer = Viewer::recipient(null, $this->site);
        $request = $request->withAttribute(Viewer::class, $viewer);
        $subject = is_string($data['subject'] ?? null) && trim($data['subject']) !== ''
            ? trim($data['subject'])
            : $this->translations->translate(new Message('Message from the contact form on {site}', ['site' => $this->site->name]), [], $viewer->locale);
        $uri = $request->getUri();
        $port = $uri->getPort() === null ? '' : ':' . $uri->getPort();
        $context = [
            'subject' => $subject,
            'name' => $name,
            'email' => $email,
            'message' => $message,
            'page_title' => $page->title,
            'page_url' => sprintf('%s://%s%s%s', $uri->getScheme(), $uri->getHost(), $port, $page->url),
        ];
        $recipient = is_string($data['recipient'] ?? null) ? $data['recipient'] : '';

        $this->mail->send(new Email()
            ->to($recipient)
            ->replyTo(new Address($email, $name))
            ->subject($subject)
            ->text($this->templates->render($request, '@contact/email/message.txt.twig', $context))
            ->html($this->templates->render($request, '@contact/email/message.html.twig', $context)));
    }
}
