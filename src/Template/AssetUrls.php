<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use InvalidArgumentException;

/**
 * Builds the URL of a static file (stylesheet, script, image of the theme), with a version so browsers fetch the
 * new file after an update.
 */
final readonly class AssetUrls
{
    /**
     * @param array<string, string> $published files with a published address that names their checksum, by asset path
     * @param string                $cdn       CDN address for styles, fonts and pictures. Scripts and the admin panel's
     *                                         files always come from the site, so a CDN can never run code on it.
     */
    public function __construct(
        private string $basePath = '/assets/',
        private ?string $version = null,
        private array $published = [],
        private string $cdn = '',
    ) {
    }

    /**
     * @param string $path relative path, for example `css/site.css`
     */
    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || preg_match('#(^|/)\.\.(/|$)|^[a-z][a-z0-9+.-]*:|\\\\#i', $path) === 1) {
            throw new InvalidArgumentException(sprintf('Asset path "%s" is not valid: use a relative path inside the assets folder.', $path));
        }

        $onSite = str_starts_with($path, 'admin/') || strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'js';
        if (!$onSite && isset($this->published[$path])) {
            return $this->published[$path];
        }

        $url = ($onSite ? '' : $this->cdn) . rtrim($this->basePath, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return $this->version === null || $this->version === '' ? $url : $url . '?v=' . rawurlencode($this->version);
    }
}
