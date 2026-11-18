<?php

declare(strict_types=1);

namespace SightPress\Core\User\Contract;

/**
 * Someone changed an account: its names, picture, time zone, language, password, email address, admin preferences or
 * status (deactivated or back). The audit log records which, never the values.
 */
final readonly class AccountChanged
{
    /**
     * @param int          $userId    whose account
     * @param ?int         $changedBy who changed it (null for the system)
     * @param list<string> $fields    what changed: `first_name`, `last_name`, `display_name`, `avatar`, `timezone`,
     *                                `locale`, `password`, `email`, `admin_preferences`, `status`
     */
    public function __construct(
        public int $userId,
        public ?int $changedBy,
        public array $fields,
    ) {
    }
}
