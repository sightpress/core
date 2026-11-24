<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Contract;

/**
 * A change to a role that {@see RoleAdministration} won't make. Each problem is keyed by what it is about: `label`,
 * `role` (the role as a whole) or the name of a permission, so a form can show it on the right field.
 */
final class RoleRefused extends \DomainException
{
    /**
     * @param non-empty-array<string, \SightPress\Core\Template\Message> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct((string) reset($problems));
    }
}
