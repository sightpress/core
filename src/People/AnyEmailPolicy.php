<?php

declare(strict_types=1);

namespace SightPress\Core\People;

/**
 * `email_policy: any`: every address may join.
 */
final readonly class AnyEmailPolicy implements EmailPolicy
{
    public function problem(string $email): ?string
    {
        return null;
    }
}
