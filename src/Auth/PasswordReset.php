<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\User\Contract\AccountPreferences;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Infrastructure\Sms\TextMessages;
use SightPress\Core\Rbac\Contract\PermissionLookup;
use SightPress\Core\Sms\Contract\SmsRefused;
use SightPress\Core\Template\Message;
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
        private AccountPreferences $preferences,
        private Translations $translations,
        private SignInIdentifier $identifiers,
        private TextMessages $texts,
        private PermissionLookup $permissions,
    ) {
    }

    /**
     * Permissions that make an account manage the site: such accounts never reset their password by text (gap 9),
     * because whoever controls the number (a SIM swap, a recycled number) could then take the site over.
     */
    public const array ADMIN_LEVEL = ['user.manage', 'role.manage', 'settings.manage', 'module.manage', 'security.manage'];

    public static function client(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : 'unknown';
    }

    /**
     * Makes a code for the address and emails it if the address has an account that can sign in, or is waiting
     * for its first password. While text messages are on, a mobile number works too: the code goes by text to an
     * account that confirmed the number and may reset that way ({@see mayResetByText()}). The answer is the same
     * whether or not anything was sent.
     *
     * @throws TooManyCodes
     */
    public function request(ServerRequestInterface $request, string $email): void
    {
        $phone = $this->identifiers->phone($email);
        if ($phone !== null) {
            $account = $this->identifiers->account($email);
            $eligible = $account !== null && $account->isActive() && $this->mayResetByText($account) ? $account : null;
            $code = $this->codes->request(VerificationPurpose::PasswordReset, VerificationChannel::Sms, $phone->e164, $eligible?->id(), self::client($request));
            if ($eligible !== null) {
                $viewer = Viewer::recipient($this->preferences->preferencesOf((int) $eligible->id()), $this->site);
                try {
                    $this->texts->sendCode($phone, $this->translations->translate(new Message('{code} is your code to reset your password for {site}. It works for {duration}. Never share it.', [
                        'code' => $code->readable(),
                        'site' => $this->site->name,
                        'duration' => CodeEntry::duration(VerificationPurpose::PasswordReset->lifetime(VerificationChannel::Sms)),
                    ]), [], $viewer->locale), $eligible->id(), self::client($request));
                } catch (SmsRefused) {
                    // Same answer either way: the person can still reset by email.
                }
            }

            return;
        }

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
        $byText = $purpose === VerificationPurpose::PasswordReset && self::isPhone($email);
        $check = $this->codes->check($purpose, $byText ? VerificationChannel::Sms : VerificationChannel::Email, $email, $code, self::client($request));
        if ($check->status === CodeStatus::Limited) {
            throw new TooManyCodes($check->retryAfter ?? 1);
        }
        $account = $check->isValid() && $check->accountId !== null ? $this->accounts->findAccount($check->accountId) : null;
        $owner = $byText ? $this->identifiers->account($email) : $account;
        if ($account === null || $owner?->id() !== $account->id() || (!$byText && mb_strtolower($account->email()) !== mb_strtolower(trim($email)))) {
            return null;
        }
        if ($byText && !$this->mayResetByText($account)) {
            return null;
        }
        $usable = $purpose === VerificationPurpose::AccountSetup
            ? $account->isPending()
            : $account->isActive() || $account->isPending();

        return $usable ? $account : null;
    }

    /**
     * Whether a code by text may reset this account's password: not for accounts that manage the site (gap 9).
     */
    public function mayResetByText(Account $account): bool
    {
        return $account->id() !== null && array_intersect(self::ADMIN_LEVEL, $this->permissions->permissionNamesOf($account->id())) === [];
    }

    /**
     * Whether a reset target is a mobile number (E.164) rather than an email address.
     */
    public static function isPhone(string $target): bool
    {
        return preg_match('/^\+[1-9]\d{6,14}$/', $target) === 1;
    }

    /**
     * Sets the new password (checked against the site's {@see PasswordPolicy} by the caller).
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

        $viewer = Viewer::recipient($account->id() === null ? null : $this->preferences->preferencesOf($account->id()), $this->site);
        $request = $request->withAttribute(Viewer::class, $viewer);
        $subject = new Message($pending ? 'Your code to choose your password for {site}' : 'Your code to reset your password for {site}', ['site' => $this->site->name]);

        $this->mail->send(new Email()
            ->to(new Address($account->email(), $account->effectiveName()))
            ->subject($this->translations->translate($subject, [], $viewer->locale))
            ->text($this->templates->render($request, '@auth/email/password.txt.twig', $context))
            ->html($this->templates->render($request, '@auth/email/password.html.twig', $context)));
    }
}
