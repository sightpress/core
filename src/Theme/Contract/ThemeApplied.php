<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Contract;

/**
 * Someone applied a theme to a site (in the admin or with `site:theme:apply`). Dispatched by the site's own dispatcher
 * after the change: the audit log records it.
 */
final readonly class ThemeApplied
{
    /**
     * @param non-empty-string $themeId
     * @param non-empty-string $name     the theme's name
     * @param string|null      $previous id of the theme the site had before; null when it had none of its own
     * @param int|null         $actorId  null when it came from the command line
     */
    public function __construct(
        public string $themeId,
        public string $name,
        public ?string $previous,
        public ?int $actorId,
    ) {
    }
}
