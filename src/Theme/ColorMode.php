<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * The four colour sets a theme provides: light and dark mode, each also with more contrast
 * (`prefers-contrast: more`). The "more contrast" sets only list the colours they change.
 */
enum ColorMode: string
{
    case Light = 'light';
    case Dark = 'dark';
    case LightMore = 'light-more';
    case DarkMore = 'dark-more';

    /**
     * How the mode is named in messages, for example "dark mode with more contrast".
     */
    public function label(): string
    {
        return match ($this) {
            self::Light => 'light mode',
            self::Dark => 'dark mode',
            self::LightMore => 'light mode with more contrast',
            self::DarkMore => 'dark mode with more contrast',
        };
    }

    /**
     * The mode whose colours this one starts from: light or dark.
     */
    public function base(): self
    {
        return match ($this) {
            self::Light, self::LightMore => self::Light,
            self::Dark, self::DarkMore => self::Dark,
        };
    }
}
