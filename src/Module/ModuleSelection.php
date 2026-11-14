<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * Which optional modules a site has turned on or off. Modules it doesn't mention use their manifest's default;
 * required modules are always on (see {@see ModuleCatalogue}).
 *
 * Change it through {@see ModuleCatalogue::turnOn()} and {@see ModuleCatalogue::turnOff()}, which refuse changes
 * that would break another module.
 */
final readonly class ModuleSelection
{
    /**
     * @param array<string, bool> $states module id => on
     */
    public function __construct(private array $states = [])
    {
    }

    /**
     * The site's own choice for a module, or null when it uses the default.
     */
    public function state(string $id): ?bool
    {
        return $this->states[$id] ?? null;
    }

    /**
     * @return array<string, bool>
     */
    public function states(): array
    {
        return $this->states;
    }

    public function with(string $id, bool $on): self
    {
        return new self([$id => $on] + $this->states);
    }
}
