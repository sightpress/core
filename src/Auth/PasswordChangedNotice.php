<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Http\Message\ServerRequestInterface;
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
 * The email after every password change (N-5, carried over from stage 2, stage-4 gap 9): on My account, after a reset
 * by email and, above all, after a reset by a code sent to the mobile number, so whoever has the mailbox hears about
 * a reset done with a stolen or recycled number. Choosing the first password of a new account sends none. No link.
 */
final readonly class PasswordChangedNotice
{
    public function __construct(
        private MailSender $mail,
        private TemplateRenderer $templates,
        private Translations $translations,
        private AccountPreferences $preferences,
        private Site $site,
    ) {
    }

    public function send(ServerRequestInterface $request, Account $account, bool $byText = false): void
    {
        $viewer = Viewer::recipient($account->id() === null ? null : $this->preferences->preferencesOf($account->id()), $this->site);
        $request = $request->withAttribute(Viewer::class, $viewer);
        $subject = new Message('The password of your account on {site} was changed', ['site' => $this->site->name]);
        $context = ['site_name' => $this->site->name, 'name' => $account->effectiveName(), 'subject' => $subject, 'by_text' => $byText];

        $this->mail->send(new Email()
            ->to(new Address($account->email(), $account->effectiveName()))
            ->subject($this->translations->translate($subject, [], $viewer->locale))
            ->text($this->templates->render($request, '@auth/email/password-changed.txt.twig', $context))
            ->html($this->templates->render($request, '@auth/email/password-changed.html.twig', $context)));
    }
}
