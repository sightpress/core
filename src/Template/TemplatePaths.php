<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

/**
 * Where templates are looked up, most specific first: the site's theme, then the default theme, then core's own
 * views. A theme can also override a module's templates in `templates/modules/<namespace>/`.
 */
final readonly class TemplatePaths
{
    /**
     * @param list<string> $themes    theme folders, most specific first (site theme, then default theme)
     * @param string       $coreViews core's own templates
     */
    public function __construct(
        public array $themes,
        public string $coreViews,
    ) {
    }

    public static function core(): string
    {
        return dirname(__DIR__, 2) . '/resources/views';
    }
}
