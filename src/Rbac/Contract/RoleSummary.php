<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * A role as the roles screen and the `site:role:*` commands show it.
 */
final readonly class RoleSummary
{
    /**
     * @param list<string> $permissions what it holds of the permissions that can be given (features that are on)
     * @param int          $people      how many people have it
     * @param bool         $fixed       `admin` and `member`, which can't be changed
     * @param bool         $signUp      whether it is the role of people who sign up
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $permissions,
        public int $people,
        public bool $fixed,
        public bool $signUp,
    ) {
    }
}
