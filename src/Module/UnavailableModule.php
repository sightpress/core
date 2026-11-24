<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * An installed external module that can't be used, and why: a broken `extra.sightpress`, a class that doesn't load,
 * an invalid manifest, an unmet constraint, a clash with another module, or a module it needs that is itself
 * unavailable. It is left out of every site rather than stopping the tenant (gap 3, ADR-0065); the *Features*
 * screen, `site:module:list`, `system:check` and the log name it with the reason.
 */
final readonly class UnavailableModule
{
    /**
     * @param non-empty-string      $source the Composer package
     * @param non-empty-string|null $id     the module id, when its manifest could be read
     */
    public function __construct(
        public string $source,
        public ?string $id,
        public string $reason,
    ) {
    }

    /**
     * How people see it: the module id with its package, or the package alone.
     */
    public function identity(): string
    {
        return $this->id === null ? $this->source : sprintf('%s (%s)', $this->id, $this->source);
    }
}
