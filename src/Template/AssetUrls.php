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
    public function __construct(
        private string $basePath = '/assets/',
        private ?string $version = null,
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

        $url = rtrim($this->basePath, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return $this->version === null || $this->version === '' ? $url : $url . '?v=' . rawurlencode($this->version);
    }
}
