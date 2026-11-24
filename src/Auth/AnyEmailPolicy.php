<?php

declare(strict_types=1);

namespace SightPress\Core\Auth;

use SightPress\Core\Auth\Contract\EmailPolicy;
use SightPress\Core\Template\Message;

/**
 * `email_policy: any`: every address may join.
 */
final readonly class AnyEmailPolicy implements EmailPolicy
{
    public function problem(string $email): ?Message
    {
        return null;
    }
}
