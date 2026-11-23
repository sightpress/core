<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use SightPress\Core\Tenant\TenantConfig;
use SightPress\Theme\ThemeManifest;

/**
 * The preview pages `theme:publish-assets` wrote for the installed themes, in the project's public folder
 * (`public/themes/<id>/<version>/preview/`), where the web server serves them as static files. The theme picker
 * shows their screenshot and links to the page.
 */
final readonly class PublishedPreviews
{
    public const string PUBLIC_PATH = '/themes';

    public function __construct(private TenantConfig $config)
    {
    }

    public function config(): TenantConfig
    {
        return $this->config;
    }

    /**
     * Folder of a theme version's preview on disk.
     *
     * @return non-empty-string
     */
    public function folder(ThemeManifest $theme): string
    {
        return $this->config->projectDir . '/public' . $this->path($theme);
    }

    /**
     * The address of a file of a theme's published preview, or null when it wasn't published.
     */
    public function url(ThemeManifest $theme, string $file): ?string
    {
        return is_file($this->folder($theme) . '/' . $file) ? $this->path($theme) . '/' . $file : null;
    }

    private function path(ThemeManifest $theme): string
    {
        return self::PUBLIC_PATH . '/' . rawurlencode($theme->id) . '/' . rawurlencode($theme->version) . '/preview';
    }
}
