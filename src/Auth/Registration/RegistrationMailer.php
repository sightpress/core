<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Registration;

use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\IssuedCode;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountPreferences;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The emails of signing up, as HTML and plain text with the same content, in the person's language: the code that
 * confirms the address, or — when the address already has an account — a notice to its owner that someone tried to
 * sign up with it. Neither has a link, a web address or an image; the site is named by its name and the code is never
 * in the subject.
 */
final readonly class RegistrationMailer
{
    public function __construct(
        private MailSender $mail,
        private TemplateRenderer $templates,
        private Site $site,
        private Translations $translations,
        private AccountPreferences $preferences,
    ) {
    }

    public function code(ServerRequestInterface $request, Account $account, IssuedCode $code): void
    {
        $this->send($request, $account, new Message('Your code to confirm your email address for {site}', ['site' => $this->site->name]), 'registration-code', [
            'code' => $code->readable(),
            'valid_for' => CodeEntry::duration(VerificationPurpose::EmailVerify->lifetime(VerificationChannel::Email)),
        ]);
    }

    public function taken(ServerRequestInterface $request, Account $owner): void
    {
        $this->send($request, $owner, new Message('Someone tried to sign up for {site} with your email address', ['site' => $this->site->name]), 'registration-taken', []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(ServerRequestInterface $request, Account $account, Message $subject, string $template, array $context): void
    {
        $viewer = Viewer::recipient($account->id() === null ? null : $this->preferences->preferencesOf($account->id()), $this->site);
        $request = $request->withAttribute(Viewer::class, $viewer);
        $context += ['site_name' => $this->site->name, 'name' => $account->effectiveName(), 'subject' => $subject];

        $this->mail->send(new Email()
            ->to(new Address($account->email(), $account->effectiveName()))
            ->subject($this->translations->translate($subject, [], $viewer->locale))
            ->text($this->templates->render($request, '@auth/email/' . $template . '.txt.twig', $context))
            ->html($this->templates->render($request, '@auth/email/' . $template . '.html.twig', $context)));
    }
}
