<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Module\ModuleInterface;
use SightPress\Module\ModuleManifest;

/**
 * A module the loader accepted, with the manifest it read once.
 */
final readonly class LoadedModule
{
    /**
     * @param non-empty-string $source `sightpress/core` or the Composer package name
     */
    public function __construct(
        public ModuleInterface $module,
        public ModuleManifest $manifest,
        public string $source,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function id(): string
    {
        return $this->manifest->id;
    }

    /**
     * @return list<non-empty-string>
     */
    public function requiredIds(): array
    {
        $ids = [];
        foreach ($this->manifest->requires as $requirement) {
            $ids[] = $requirement->moduleId;
        }

        return $ids;
    }
}
