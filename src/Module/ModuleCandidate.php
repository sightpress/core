<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Module\ModuleInterface;

/**
 * A module found by discovery, before the loader checks it.
 */
final readonly class ModuleCandidate
{
    /**
     * @param non-empty-string      $source             where it comes from: `sightpress/core` or the Composer package
     *                                                  name, used in error messages
     * @param non-empty-string|null $contractConstraint the package's constraint on `sightpress/module-interface`;
     *                                                  null for core's own modules
     */
    public function __construct(
        public ModuleInterface $module,
        public string $source,
        public ?string $contractConstraint = null,
    ) {
    }
}
