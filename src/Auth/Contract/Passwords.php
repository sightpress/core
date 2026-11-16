<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * The site's password rules and hashing, for every place where people choose a password.
 */
interface Passwords
{
    /**
     * What is wrong with the password, in plain words, or null when it is fine.
     */
    public function problem(#[\SensitiveParameter] string $password, string $email = ''): ?string;

    /**
     * An argon2id hash of the password.
     */
    public function hash(#[\SensitiveParameter] string $password): string;
}
