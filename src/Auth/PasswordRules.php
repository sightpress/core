<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SensitiveParameter;
use SightPress\Core\Auth\Contract\Passwords;

/**
 * The {@see PasswordPolicy} and the {@see PasswordHasher}, for other modules.
 */
final readonly class PasswordRules implements Passwords
{
    public function __construct(private PasswordHasher $hasher)
    {
    }

    public function problem(#[SensitiveParameter] string $password, string $email = ''): ?string
    {
        return PasswordPolicy::problem($password, $email);
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return $this->hasher->hash($password);
    }
}
