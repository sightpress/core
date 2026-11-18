<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Checks an email address and password, and starts and ends signed-in sessions.
 *
 * Attempts are limited per email address and client address (`sign_in` rate limit: 5 in 15 minutes). A wrong
 * address, a wrong password and an account that can't sign in all give the same answer, after the same work. Only
 * with the right password does someone learn that an account they made themselves still waits for its email
 * address to be confirmed.
 */
final readonly class SignIn
{
    public const string SESSION_USER = 'auth.user_id';
    public const string SESSION_SITE = 'auth.site_id';
    public const string SESSION_STAMP = 'auth.password_stamp';

    public function __construct(
        private Accounts $accounts,
        private PasswordHasher $hasher,
        private RateLimiters $limiters,
        private ClockInterface $clock,
        private Site $site,
    ) {
    }

    public function attempt(ServerRequestInterface $request, string $email, #[SensitiveParameter] string $password): SignInResult
    {
        $limiter = $this->limiter($request, $email);
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return SignInResult::throttled(
                RateLimiters::secondsUntil($limit->getRetryAfter()),
            );
        }

        $account = $this->accounts->findAccountByEmail($email);
        $hash = $account?->passwordHash();
        if (!$this->hasher->verify($password, $hash) || $account === null || $hash === null) {
            return SignInResult::wrongCredentials();
        }
        if ($account->awaitsEmailVerification()) {
            $limiter->reset();

            return SignInResult::unconfirmed($account);
        }
        if (!$account->isActive()) {
            return SignInResult::wrongCredentials();
        }

        $limiter->reset();
        if ($this->hasher->needsRehash($hash)) {
            $account->setPasswordHash($this->hasher->hash($password), $this->clock->now());
            $this->accounts->saveAccount($account);
        }

        return SignInResult::signedIn($account);
    }

    /**
     * Checks the password of someone already signed in, before they change their password or email address. Wrong
     * tries count towards the same `sign_in` limit as signing in, so it can't be used to guess passwords faster.
     */
    public function confirmPassword(ServerRequestInterface $request, Account $account, #[SensitiveParameter] string $password): SignInResult
    {
        $limiter = $this->limiter($request, $account->email());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return SignInResult::throttled(RateLimiters::secondsUntil($limit->getRetryAfter()));
        }
        if (!$this->hasher->verify($password, $account->passwordHash())) {
            return SignInResult::wrongCredentials();
        }
        $limiter->reset();

        return SignInResult::signedIn($account);
    }

    /**
     * Signs the account in for this session; the session gets a new id, so an old one can't be reused. The session
     * remembers a stamp of the password, so changing the password signs out every other session.
     */
    public function start(ServerRequestInterface $request, Account $account): void
    {
        $session = Session::fromRequest($request);
        $session->regenerate();
        $session->set(self::SESSION_USER, $account->id());
        $session->set(self::SESSION_SITE, $this->site->id);
        $session->set(self::SESSION_STAMP, self::stamp($account));
    }

    /**
     * Whether the session was signed in with the account's current password.
     */
    public function isCurrent(ServerRequestInterface $request, Account $account): bool
    {
        $stamp = Session::fromRequest($request)->get(self::SESSION_STAMP);

        return is_string($stamp) && hash_equals(self::stamp($account), $stamp);
    }

    /**
     * Signs out: the session is emptied and gets a new id.
     */
    public function end(ServerRequestInterface $request): void
    {
        Session::fromRequest($request)->invalidate();
    }

    /**
     * The id of the user signed in to this site in the request's session, or null.
     */
    public function userId(ServerRequestInterface $request): ?int
    {
        $session = Session::fromRequest($request);
        $user = $session->get(self::SESSION_USER);

        return $session->get(self::SESSION_SITE) === $this->site->id && is_int($user) ? $user : null;
    }

    private function limiter(ServerRequestInterface $request, string $email): LimiterInterface
    {
        $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return $this->limiters->limiter('sign_in', mb_strtolower(trim($email)) . '|' . (is_string($address) ? $address : 'unknown'));
    }

    private static function stamp(Account $account): string
    {
        return substr(hash('sha256', 'sightpress session ' . ($account->passwordHash() ?? '')), 0, 32);
    }
}
