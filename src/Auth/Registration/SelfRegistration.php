<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Registration;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Auth\Contract\CodeStatus;
use SightPress\Core\Auth\Contract\DomainRegistration;
use SightPress\Core\Auth\Contract\Passwords;
use SightPress\Core\Auth\Contract\SignUp;
use SightPress\Core\Auth\Contract\TooManyCodes;
use SightPress\Core\Auth\Contract\VerificationChannel;
use SightPress\Core\Auth\Contract\VerificationCodes;
use SightPress\Core\Auth\Contract\VerificationPurpose;
use SightPress\Module\Event\AccountRegistered;
use SightPress\Core\Rbac\Contract\RoleGrants;
use SightPress\Core\Rbac\Contract\SignUpRoles;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;
use SightPress\Core\User\Contract\UnverifiedAccounts;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\DomainName;

/**
 * Self-registration (U-1): while the site lets anyone sign up ({@see SignUp::SETTING}, off by default) — or, on a
 * members-only site with domain registration (X-7, {@see DomainRegistration}), anyone with an address at the
 * organisation's domains — a visitor makes an account that can't sign in until they type the six-digit code we email them (purpose `email_verify`, never
 * a link). Then it gets the site's sign-up role — `member` unless an admin chose another sign-up-safe role — and its
 * avatar.
 *
 * Nobody learns which addresses have an account: an address that already has one gets the same answer and the same
 * limits, and its owner gets a notice instead of a code.
 */
final readonly class SelfRegistration
{
    public function __construct(
        private SiteSettings $settings,
        private UnverifiedAccounts $unverified,
        private Accounts $accounts,
        private VerificationCodes $codes,
        private Passwords $passwords,
        private RegistrationMailer $mailer,
        private SignUpRoles $registrationRole,
        private RoleGrants $roles,
        private EventDispatcherInterface $events,
        private DomainRegistration $domainRegistration,
    ) {
    }

    /**
     * Whether someone can make their own account: anyone may, or people at the organisation's domains may.
     */
    public function isOpen(): bool
    {
        return $this->isOpenToAnyone() || $this->domainRegistration->domains() !== null;
    }

    public function isOpenToAnyone(): bool
    {
        return SignUp::isOpen($this->settings->get(SignUp::SETTING));
    }

    /**
     * The domains whose addresses may sign up, while only those may (sign-up is off but domain registration is on);
     * null when anyone may, or nobody.
     *
     * @return non-empty-list<string>|null
     */
    public function onlyDomains(): ?array
    {
        return $this->isOpenToAnyone() ? null : $this->domainRegistration->domains();
    }

    /**
     * Why the address may not sign up here, or null when it may.
     */
    public function problem(string $email): ?Message
    {
        $domains = $this->onlyDomains();
        if ($domains === null || in_array(DomainName::ofEmail($email), $domains, true)) {
            return null;
        }

        return new Message('Only people with an email address at {domains} can create an account here. Use your address there.', ['domains' => Message::list($domains)]);
    }

    /**
     * Makes (or updates) the account waiting for its code and emails the code; an address that has another account
     * gets a notice instead.
     *
     * @throws TooManyCodes
     */
    public function start(ServerRequestInterface $request, RegistrationDetails $details): void
    {
        $account = $this->unverified->startUnverified(
            $details->email,
            $details->firstName,
            $details->lastName,
            $details->displayName,
            $details->timezone,
            $details->locale,
            $this->passwords->hash($details->password),
        );
        $owner = $account === null ? $this->accounts->findAccountByEmail($details->email) : null;
        $code = $this->codes->request(VerificationPurpose::EmailVerify, VerificationChannel::Email, $details->email, $account?->id(), self::client($request));
        if ($account !== null) {
            $this->mailer->code($request, $account, $code);
        } elseif ($owner !== null) {
            $this->mailer->taken($request, $owner);
        }
    }

    /**
     * Emails a new code to an account still waiting for one. Other addresses get nothing, with the same limits.
     *
     * @throws TooManyCodes
     */
    public function resend(ServerRequestInterface $request, string $email): void
    {
        $account = $this->accounts->findAccountByEmail($email);
        $waiting = $account !== null && $account->awaitsEmailVerification() ? $account : null;
        $code = $this->codes->request(VerificationPurpose::EmailVerify, VerificationChannel::Email, $email, $waiting?->id(), self::client($request));
        if ($waiting !== null) {
            $this->mailer->code($request, $waiting, $code);
        }
    }

    /**
     * Checks the code. A right one confirms the account, which gets its avatar and the sign-up role; null when the
     * code is wrong, used or dead, or the account no longer waits for it.
     *
     * @throws TooManyCodes when this client tried too many codes
     */
    public function confirm(ServerRequestInterface $request, string $email, string $code): ?Account
    {
        $check = $this->codes->check(VerificationPurpose::EmailVerify, VerificationChannel::Email, $email, $code, self::client($request));
        if ($check->status === CodeStatus::Limited) {
            throw new TooManyCodes($check->retryAfter ?? 1);
        }
        $waiting = $check->isValid() && $check->accountId !== null ? $this->accounts->findAccount($check->accountId) : null;
        if ($waiting === null || mb_strtolower($waiting->email()) !== mb_strtolower(trim($email))) {
            return null;
        }
        $account = $this->unverified->confirm($check->accountId);
        if ($account === null) {
            return null;
        }
        $role = $this->registrationRole->forNewAccount();
        $stored = $this->registrationRole->name();
        $this->roles->assign((int) $account->id(), $role);
        $this->events->dispatch(new AccountRegistered((int) $account->id(), $role, $stored === $role ? null : $stored));

        return $account;
    }

    private static function client(ServerRequestInterface $request): string
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($address) ? $address : 'unknown';
    }
}
