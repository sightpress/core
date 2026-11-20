<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Theme;

use SightPress\Theme\LayoutDefinition;
use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SightPress\Theme\ThemePaths;
use SightPress\Theme\ThemeRequirement;

/**
 * A third-party theme as a theme package names it in `extra.sightpress.theme` (its files are in `harbour/`, the
 * contract's own fake theme), for the theme tests.
 */
final class HarbourTheme implements ThemeInterface
{
    public function manifest(): ThemeManifest
    {
        return new ThemeManifest(
            'acme-harbour',
            'Harbour',
            'Calm blues with large type.',
            '1.2.0',
            new ThemeRequirement('^0.2'),
            layouts: [...LayoutDefinition::required(), LayoutDefinition::standard()[1]],
            previewDescription: 'A dark blue top bar and a large white heading.',
        );
    }

    public function paths(): ThemePaths
    {
        return new ThemePaths(__DIR__ . '/harbour');
    }
}
