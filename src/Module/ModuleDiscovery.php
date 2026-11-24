<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * What {@see ComposerModuleDiscovery} found: the modules to check and load, and the packages that declare a module
 * that can't even be created.
 */
final readonly class ModuleDiscovery
{
    /**
     * @param list<ModuleCandidate>   $candidates
     * @param list<UnavailableModule> $unavailable
     */
    public function __construct(
        public array $candidates = [],
        public array $unavailable = [],
    ) {
    }
}
