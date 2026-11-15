<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SensitiveParameter;

/**
 * Hashes passwords with argon2id and checks them. Checking against a missing hash still does the work of a real
 * check, so the time a sign-in takes doesn't tell whether the email address has an account.
 */
final class PasswordHasher
{
    private ?string $dummy = null;

    /**
     * @param array{memory_cost?: int, time_cost?: int, threads?: int} $options argon2id cost; PHP's defaults when empty
     */
    public function __construct(private readonly array $options = [])
    {
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(#[SensitiveParameter] string $password, ?string $hash): bool
    {
        if ($hash === null) {
            password_verify($password, $this->dummy ??= $this->hash('not a real password'));

            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Whether a hash was made with other settings and should be made again at the next sign-in.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }
}
