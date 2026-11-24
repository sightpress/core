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
     * @param non-empty-string $source   `sightpress/core` or the Composer package name
     * @param bool             $external installed as a package of its own, so a problem with it leaves it out
     *                                   rather than stopping the tenant (ADR-0065)
     */
    public function __construct(
        public ModuleInterface $module,
        public ModuleManifest $manifest,
        public string $source,
        public ?ModuleAbout $about = null,
        public bool $external = false,
    ) {
    }

    /**
     * The module's name for people: from {@see ModuleAbout}, otherwise its id.
     */
    public function name(): string
    {
        return $this->about->name ?? $this->manifest->id;
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
