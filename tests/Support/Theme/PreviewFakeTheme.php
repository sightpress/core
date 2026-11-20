<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Theme;

use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SightPress\Theme\ThemePaths;
use SightPress\Theme\ThemeRequirement;

/**
 * A theme class as a theme package names it in `extra.sightpress.theme`, for the preview tests.
 */
final class PreviewFakeTheme implements ThemeInterface
{
    public function manifest(): ThemeManifest
    {
        return new ThemeManifest(
            'acme-harbour',
            'Harbour',
            'Calm blues with large type.',
            '1.2.0',
            new ThemeRequirement('^0.2'),
            previewDescription: 'A dark blue top bar and a large white heading.',
        );
    }

    public function paths(): ThemePaths
    {
        return new ThemePaths(__DIR__);
    }
}
