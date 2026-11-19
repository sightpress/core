<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

/**
 * One optional module (a "feature") as a site has it: what it is, whether it is on, and what stops it from changing.
 */
final readonly class SiteModuleState
{
    /**
     * @param list<string> $requires names of the modules it needs
     * @param list<string> $neededBy names of the modules that are on and need it
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $whenOff,
        public bool $on,
        public bool $locked,
        public array $requires = [],
        public array $neededBy = [],
    ) {
    }
}
