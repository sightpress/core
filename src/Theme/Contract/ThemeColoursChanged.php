<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Contract;

/**
 * Someone changed the site's own colours, or went back to the theme's. Dispatched by the site's own dispatcher after
 * the change: the audit log records it.
 */
final readonly class ThemeColoursChanged
{
    /**
     * @param non-empty-string $themeId
     * @param list<string>     $colours the colours that now differ from the theme's, as `light.primary`; empty after
     *                                  a reset
     */
    public function __construct(
        public string $themeId,
        public string $name,
        public array $colours,
        public ?int $actorId,
    ) {
    }
}
