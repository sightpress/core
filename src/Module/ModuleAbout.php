<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

/**
 * What the *Features* screen says about an optional module, in plain words: its name, what it does and what turning
 * it off does. External modules give it in their package's `composer.json` (`extra.sightpress.features.<module id>`
 * with `name`, `description` and `when-off`); a module without it is shown by its id.
 */
final readonly class ModuleAbout
{
    public function __construct(
        public string $name,
        public string $description = '',
        public string $whenOff = '',
    ) {
    }

    /**
     * Reads one entry of `extra.sightpress.features`; anything that isn't text is left out.
     *
     * @param array<mixed> $entry
     */
    public static function fromArray(array $entry, string $fallbackName): self
    {
        $text = static fn (string $key): string => is_string($entry[$key] ?? null) ? trim($entry[$key]) : '';
        $name = $text('name');

        return new self($name === '' ? $fallbackName : $name, $text('description'), $text('when-off'));
    }
}
