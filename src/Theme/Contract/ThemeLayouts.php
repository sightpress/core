<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Contract;

/**
 * The layouts of the site's theme (T-3): which ones it provides, and the template a page in a layout is shown with —
 * its own when the theme has it, otherwise the standard one (`default`). A layout file of the default theme that the
 * site's theme leaves out doesn't count: the theme's manifest decides.
 */
interface ThemeLayouts
{
    /**
     * Whether the site's theme provides the layout.
     */
    public function provides(string $layout): bool;

    /**
     * The layout template a page in this layout is shown with, for example `layouts/sidebar.html.twig`.
     */
    public function template(string $layout): string;
}
