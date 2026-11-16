<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * A theme: its folder (with `theme.json`, `templates/` and `assets/`) and what `theme.json` says about it.
 */
final readonly class Theme
{
    /**
     * @param non-empty-string $name      machine name, for example `default`
     * @param non-empty-string $label     name shown to people
     * @param non-empty-string $version
     * @param non-empty-string $directory absolute path of the theme folder
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $version,
        public string $directory,
        public ThemeTokens $tokens,
    ) {
    }
}
