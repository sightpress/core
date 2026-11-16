<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use SightPress\Core\Theme\Contract\PageColours;

/**
 * The `text` and `background` tokens of the site's theme in every colour mode that has both.
 */
final readonly class ThemePageColours implements PageColours
{
    public function __construct(private Theme $theme)
    {
    }

    public function pageColours(): array
    {
        $colours = [];
        foreach (ColorMode::cases() as $mode) {
            $text = $this->theme->tokens->color('text', $mode);
            $background = $this->theme->tokens->color('background', $mode);
            if ($text !== null && $background !== null) {
                $colours[$mode->label()] = ['text' => $text->hex, 'background' => $background->hex];
            }
        }

        return $colours;
    }
}
