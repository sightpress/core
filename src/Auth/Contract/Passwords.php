<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * The site's password policy and hashing, for every place where people choose a password. Every site has a policy
 * (length and kinds of characters); its admin can change it in the site settings.
 */
interface Passwords
{
    /** Site setting: the shortest password allowed, in characters (default 12). */
    public const string MIN_LENGTH_SETTING = 'auth.password_min_length';

    /** The shortest minimum length a site can choose. */
    public const int SHORTEST_MIN_LENGTH = 8;

    /** The longest password anyone can choose, and so the longest minimum length a site can choose. */
    public const int MAX_LENGTH = 256;

    /**
     * What is wrong with the password, in plain words, or null when it is fine.
     */
    public function problem(#[\SensitiveParameter] string $password, string $email = ''): ?string;

    /**
     * What a new password needs under the site's policy, in one sentence for the hint under the password field,
     * for example "At least 12 characters, including a capital letter, a lower-case letter, a number and a symbol,
     * such as ! ? # or %."
     */
    public function hint(): string;

    /**
     * An argon2id hash of the password.
     */
    public function hash(#[\SensitiveParameter] string $password): string;
}
