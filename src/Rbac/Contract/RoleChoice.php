<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * A role as people choose it: its name, its label and what it lets someone do, in plain words.
 */
final readonly class RoleChoice
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
    ) {
    }
}
