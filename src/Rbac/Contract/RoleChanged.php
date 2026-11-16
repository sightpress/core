<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * Someone gave a user another role. The audit log records it.
 */
final readonly class RoleChanged
{
    /**
     * @param ?string $from the role they had, or null when they had none
     */
    public function __construct(
        public int $userId,
        public ?int $changedBy,
        public ?string $from,
        public string $to,
    ) {
    }
}
