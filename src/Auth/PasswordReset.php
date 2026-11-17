<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use SightPress\Core\Auth\Contract\CodeEntry;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\IssuedCode;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Core\Infrastructure\Mail\MailSender;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * "Forgot your password?" and the first admin's setup: a six-digit code by email (or printed by `site:create`),
 * typed on a page of the site, and then the new password. Never a link.
 *
 * Asking for a code does the same work and gives the same answer whether or not the address has an account, so
 * nobody can find out which addresses have one. Setting a password activates a pending account, signs out other
 * sessions and forgets every "keep me signed in" device.
 */
final readonly class PasswordReset
{
    public function __construct(
        private Accounts $accounts,
        private VerificationCodes $codes,
        private PasswordHasher $hasher,
        private RememberTokens $rememberTokens,
        private MailSender $mail,
        private TemplateRenderer $templates,
        private ClockInterface $clock,
        private Site $site,
    ) {
    }

    public static function client(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : 'unknown';
    }

    /**
     * Makes a code for the address and emails it if the address has an account that can sign in, or is waiting
     * for its first password.
     *
     * @throws TooManyCodes
     */
    public function request(ServerRequestInterface $request, string $email): void
    {
        $account = $this->accounts->findAccountByEmail($email);
        $eligible = $account !== null && ($account->isActive() || $account->isPending()) ? $account : null;
        $code = $this->codes->request(
            VerificationPurpose::PasswordReset,
            VerificationChannel::Email,
            $email,
            $eligible?->id(),
            self::client($request),
        );
        if ($eligible !== null) {
            $this->send($request, $eligible, $code);
        }
    }

    /**
     * The account a code proves, or null when the code is wrong, used, expired or dead, or the account can no
     * longer use it: a reset code needs an account that can sign in or is pending, a setup code a pending one.
     *
     * @throws TooManyCodes when this client tried too many codes
     */
    public function verify(ServerRequestInterface $request, VerificationPurpose $purpose, string $email, string $code): ?Account
    {
        $check = $this->codes->check($purpose, VerificationChannel::Email, $email, $code, self::client($request));
        if ($check->status === CodeStatus::Limited) {
            throw new TooManyCodes($check->retryAfter ?? 1);
        }
        $account = $check->isValid() && $check->accountId !== null ? $this->accounts->findAccount($check->accountId) : null;
        if ($account === null || mb_strtolower($account->email()) !== mb_strtolower(trim($email))) {
            return null;
        }
        $usable = $purpose === VerificationPurpose::AccountSetup
            ? $account->isPending()
            : $account->isActive() || $account->isPending();

        return $usable ? $account : null;
    }

    /**
     * Sets the new password (checked against {@see PasswordPolicy} by the caller).
     */
    public function complete(Account $account, #[SensitiveParameter] string $password): void
    {
        $account->setPasswordHash($this->hasher->hash($password), $this->clock->now());
        $this->accounts->saveAccount($account);
        $this->rememberTokens->forgetUser((int) $account->id());
    }

    private function send(ServerRequestInterface $request, Account $account, IssuedCode $code): void
    {
        $pending = $account->isPending();
        $context = [
            'site_name' => $this->site->name,
            'name' => $account->effectiveName(),
            'code' => $code->readable(),
            'pending' => $pending,
            'valid_for' => CodeEntry::duration(VerificationPurpose::PasswordReset->lifetime(VerificationChannel::Email)),
        ];

        $this->mail->send(new Email()
            ->to(new Address($account->email(), $account->effectiveName()))
            ->subject(sprintf(
                $pending ? 'Your code to choose your password for %s' : 'Your code to reset your password for %s',
                $this->site->name,
            ))
            ->text($this->templates->render($request, '@auth/email/password.txt.twig', $context))
            ->html($this->templates->render($request, '@auth/email/password.html.twig', $context)));
    }
}
