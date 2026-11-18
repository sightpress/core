<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Which email addresses may join the site, checked when someone is invited, when they accept and when they sign up.
 * For now every address may ({@see \SightPress\Core\Auth\AnyEmailPolicy}); limiting sign-ups to the organisation's
 * domains comes later (tenant setting `email_policy`, with domain registration on members-only sites).
 */
interface EmailPolicy
{
    /**
     * Why the address may not join, in plain words, or null when it may.
     */
    public function problem(string $email): ?string;
}
