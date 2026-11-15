<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SensitiveParameter;

/**
 * What makes a password acceptable: long enough to be hard to guess, short enough to hash, and not the email
 * address. No rules about character kinds: a long phrase is easier to remember and harder to guess.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 256;

    /**
     * The problem with the password, as a sentence for the form, or null when it is fine.
     */
    public static function problem(#[SensitiveParameter] string $password, string $email = ''): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return sprintf('Your password must be at least %d characters long.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            return sprintf('Your password must be %d characters or fewer.', self::MAX_LENGTH);
        }
        if ($email !== '' && mb_strtolower(trim($password)) === mb_strtolower(trim($email))) {
            return 'Your password must not be your email address.';
        }

        return null;
    }
}
