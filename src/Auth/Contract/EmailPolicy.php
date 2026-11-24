<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

use SightPress\Core\Template\Message;

/**
 * Which email addresses may join the site, checked when someone is invited, when they accept, when they sign up and
 * when they change their address. The tenant setting `email_policy` chooses: every address
 * ({@see \SightPress\Core\Auth\AnyEmailPolicy}) or only those at the organisation's domains
 * ({@see \SightPress\Core\Auth\DomainsEmailPolicy}, X-5).
 */
interface EmailPolicy
{
    /**
     * Why the address may not join, in plain words, or null when it may.
     */
    public function problem(string $email): ?Message;
}
