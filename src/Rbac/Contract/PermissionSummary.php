<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * A permission that can be given on the site, with what it allows in plain words.
 */
final readonly class PermissionSummary
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public bool $allowedOnSignUp,
    ) {
    }
}
