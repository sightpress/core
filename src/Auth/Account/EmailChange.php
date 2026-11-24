<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Account;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Auth\Contract\VerificationTarget;
use SightPress\Core\Auth\PasswordReset;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\User\Contract\Accounts;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * A signed-in person moves their account to another email address: a six-digit `email_change` code goes to the new
 * address, and only once it is typed back does the account change. The old address then gets a notice, without a
 * link. Neither email has a web address or an image.
 *
 * Nobody learns from it which addresses have an account: an address that already has one is answered the same way,
 * but no code is sent to it, so the change can't be finished.
 */
final readonly class EmailChange
{
    public function __construct(
        private Accounts $accounts,
        private VerificationCodes $codes,
        private EmailPolicy $policy,
        private MailSender $mail,
        private TemplateRenderer $templates,
        private Site $site,
        private Translations $translations,
        private AccountPreferences $preferences,
        private ClockInterface $clock,
    ) {
    }

    /**
     * What is wrong with the new address, in plain words, or null when a code can be sent to it.
     */
    public function problem(Account $account, string $email): string|Message|null
    {
        if (self::normalise($email) === self::normalise($account->email())) {
            return 'That is already your email address. Enter the new one.';
        }

        return $this->policy->problem(self::normalise($email));
    }

    /**
     * Makes a code for the new address and emails it there, unless the address already has an account.
     *
     * @throws TooManyCodes
     */
    public function request(ServerRequestInterface $request, Account $account, string $email): void
    {
        $email = self::normalise($email);
        $taken = $this->accounts->findAccountByEmail($email) !== null;
        $code = $this->codes->request(VerificationPurpose::EmailChange, VerificationChannel::Email, $email, $account->id(), PasswordReset::client($request));
        if (!$taken) {
            $this->send($request, $account, $email, new Message('Your code to confirm your new email address for {site}', ['site' => $this->site->name]), 'email-change-code', [
                'code' => $code->readable(),
                'valid_for' => CodeEntry::duration(VerificationPurpose::EmailChange->lifetime(VerificationChannel::Email)),
            ]);
        }
    }

    /**
     * Checks the code and, when it is right, for this account and the address is still free, moves the account to the
     * new address and tells the old one. Returns whether the address changed.
     *
     * @throws TooManyCodes when this client tried too many codes
     */
    public function confirm(ServerRequestInterface $request, Account $account, string $email, string $code): bool
    {
        $email = self::normalise($email);
        $check = $this->codes->check(VerificationPurpose::EmailChange, VerificationChannel::Email, $email, $code, PasswordReset::client($request));
        if ($check->status === CodeStatus::Limited) {
            throw new TooManyCodes($check->retryAfter ?? 1);
        }
        if (!$check->isValid() || $check->accountId === null || $check->accountId !== $account->id() || $this->accounts->findAccountByEmail($email) !== null) {
            return false;
        }

        $old = $account->email();
        $account->changeEmail($email, $this->clock->now());
        $this->accounts->saveAccount($account);
        $this->send($request, $account, $old, new Message('The email address of your account on {site} has changed', ['site' => $this->site->name]), 'email-changed', [
            'new_email' => VerificationTarget::mask(VerificationChannel::Email, $email),
        ]);

        return true;
    }

    private static function normalise(string $email): string
    {
        return VerificationTarget::normalise(VerificationChannel::Email, $email);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(ServerRequestInterface $request, Account $account, string $to, Message $subject, string $template, array $context): void
    {
        $viewer = Viewer::recipient($account->id() === null ? null : $this->preferences->preferencesOf($account->id()), $this->site);
        $request = $request->withAttribute(Viewer::class, $viewer);
        $context += ['site_name' => $this->site->name, 'name' => $account->effectiveName(), 'subject' => $subject];

        $this->mail->send(new Email()
            ->to(new Address($to, $account->effectiveName()))
            ->subject($this->translations->translate($subject, [], $viewer->locale))
            ->text($this->templates->render($request, '@auth/email/' . $template . '.txt.twig', $context))
            ->html($this->templates->render($request, '@auth/email/' . $template . '.html.twig', $context)));
    }
}
