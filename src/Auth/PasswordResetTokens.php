<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use Psr\Clock\ClockInterface;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\Account;
use SightPress\Core\User\Contract\Accounts;

/**
 * Signed links for choosing a password: "forgot password" and the first admin's "set your password".
 *
 * A token names the account and when it expires, and is signed (HMAC-SHA256, key derived from `APP_SECRET`) over
 * the site, the account, its email address and its current password hash. Nothing is stored. A token works once:
 * setting the password changes the hash, which breaks the signature of every token made before.
 */
final readonly class PasswordResetTokens
{
    /** How long a "forgot password" link works, in seconds. */
    public const int RESET_TTL = 3600;

    /** How long a first "set your password" link works, in seconds. */
    public const int SETUP_TTL = 604800;

    private string $key;

    public function __construct(
        private Accounts $accounts,
        private Site $site,
        private ClockInterface $clock,
        #[\SensitiveParameter] string $secret,
    ) {
        $this->key = hash_hkdf('sha256', $secret, 32, 'sightpress password reset');
    }

    /**
     * @param positive-int $ttl seconds until the link stops working
     */
    public function create(Account $account, int $ttl = self::RESET_TTL): string
    {
        $id = $account->id() ?? throw new \LogicException('Save the account before making a link for it.');
        $payload = $id . ':' . ($this->clock->now()->getTimestamp() + $ttl);

        return self::encode($payload) . '.' . self::encode($this->sign($payload, $account));
    }

    /**
     * The account the token is for, or null when it is malformed, expired, already used or forged.
     */
    public function verify(string $token): ?Account
    {
        if (preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $parts) !== 1) {
            return null;
        }
        $payload = self::decode($parts[1]);
        if (preg_match('/^(\d{1,18}):(\d{1,18})$/', $payload, $fields) !== 1) {
            return null;
        }
        if ((int) $fields[2] < $this->clock->now()->getTimestamp()) {
            return null;
        }

        $account = $this->accounts->findAccount((int) $fields[1]);
        if ($account === null || !hash_equals($this->sign($payload, $account), self::decode($parts[2]))) {
            return null;
        }

        return $account;
    }

    private function sign(string $payload, Account $account): string
    {
        return hash_hmac(
            'sha256',
            implode("\n", [$this->site->id, $payload, $account->email(), $account->passwordHash() ?? '']),
            $this->key,
            true,
        );
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
