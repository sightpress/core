<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * A foreground colour the theme puts on a background colour, declared in `theme.json` under `contrast`, so the
 * {@see ThemeValidator} can check it in every colour mode.
 */
final readonly class ContrastPair
{
    /**
     * @param non-empty-string $foreground colour name, for example `text`
     * @param non-empty-string $background colour name, for example `surface`
     */
    public function __construct(
        public string $foreground,
        public string $background,
        public ContrastUse $use,
    ) {
    }
}
