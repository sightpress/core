<?php

declare(strict_types=1);

namespace SightPress\Core\People;

/**
 * Which email addresses may join the site, checked when someone is invited and when they accept. M1 allows every
 * address ({@see AnyEmailPolicy}); limiting sign-ups to the organisation's domains comes later (tenant setting
 * `email_policy`).
 */
interface EmailPolicy
{
    /**
     * Why the address may not join, in plain words, or null when it may.
     */
    public function problem(string $email): ?string;
}
