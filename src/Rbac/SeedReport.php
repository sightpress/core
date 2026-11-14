<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac;

/**
 * What {@see RbacSeeder::seed()} did.
 */
final readonly class SeedReport
{
    /**
     * @param list<string> $addedPermissions names of permissions new to the site
     * @param list<string> $addedRoles       names of roles created
     * @param list<string> $skippedGrants    default grants a rule refused, each as a sentence
     */
    public function __construct(
        public array $addedPermissions = [],
        public array $addedRoles = [],
        public array $skippedGrants = [],
    ) {
    }
}
