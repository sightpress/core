<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Contract;

/**
 * The text and background colours of the site's theme in each colour mode, for checks of colours editors choose
 * (text colour in rich text must stay readable in light and dark mode).
 */
interface PageColours
{
    /**
     * @return array<string, array{text: string, background: string}> keyed by how the mode is named in messages,
     *                                                                  for example "dark mode"; colours as `#rrggbb`
     */
    public function pageColours(): array;
}
