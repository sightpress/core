<?php

declare(strict_types=1);

namespace SightPress\Core\Cli\ThemePreview;

use SightPress\Core\Theme\Preview\PreviewTheme;
use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SightPress\Theme\ThemePaths;
use SightPress\Theme\ThemeRequirement;

/**
 * The theme in a folder, as the preview's throwaway site installs it: a theme checkout or a theme that isn't installed
 * with Composer, described by what the preview read about it.
 */
final readonly class FolderTheme implements ThemeInterface
{
    public function __construct(private PreviewTheme $theme)
    {
    }

    public function manifest(): ThemeManifest
    {
        return new ThemeManifest(
            $this->theme->id,
            $this->theme->name,
            $this->theme->description,
            $this->theme->version,
            new ThemeRequirement('*'),
            previewDescription: $this->theme->previewDescription ?? $this->theme->description,
        );
    }

    public function paths(): ThemePaths
    {
        return new ThemePaths($this->theme->directory);
    }
}
