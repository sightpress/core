<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * A role itself changed: created, renamed, given or refused permissions, or deleted (X-4). Someone getting another
 * role is {@see \SightPress\Module\Event\RoleChanged}. Audited.
 */
final readonly class RoleEdited
{
    public const string CREATED = 'created';
    public const string RENAMED = 'renamed';
    public const string PERMISSIONS = 'permissions_changed';
    public const string DELETED = 'deleted';

    /**
     * @param string       $change  one of the constants
     * @param string       $role    the role's name (after a rename, the new one)
     * @param string       $label   its label (after a rename, the new one)
     * @param int|null     $actorId who changed it; null for the command line
     * @param list<string> $added   permissions it got
     * @param list<string> $removed permissions it lost
     * @param string|null  $from    the label before a rename
     */
    public function __construct(
        public string $change,
        public string $role,
        public string $label,
        public ?int $actorId,
        public array $added = [],
        public array $removed = [],
        public ?string $from = null,
    ) {
    }
}
