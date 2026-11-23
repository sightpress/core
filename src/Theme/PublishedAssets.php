<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Theme\ThemeManifest;
use SplFileInfo;

/**
 * A theme version's static files, copied to `public/themes/<id>/<version>/assets-<checksum>/`. The checksum of the
 * whole set is in the folder name, not in each file's, so links between a theme's files keep working. A CDN can keep
 * them for good: changed files get a new folder. `assets.json` lists them.
 */
final readonly class PublishedAssets
{
    private const string MANIFEST = 'assets.json';

    public function __construct(private TenantConfig $config)
    {
    }

    /**
     * Copies the files, unless this exact set is already published.
     */
    public function publish(ThemeManifest $theme, string $assets): void
    {
        if (!is_dir($assets)) {
            return;
        }
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($assets) + 1)] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files);
        $name = 'assets-' . substr(hash('sha256', (string) json_encode($files, JSON_THROW_ON_ERROR)), 0, 16);
        $base = $this->folder($theme);
        foreach (array_keys($files) as $path) {
            $target = $base . '/' . $name . '/' . $path;
            if ((!is_dir(dirname($target)) && !@mkdir(dirname($target), 0o777, true)) || !@copy($assets . '/' . $path, $target)) {
                throw new ThemeException(sprintf('The file %s could not be written to the public folder.', $path));
            }
        }
        // Written last: the list only ever names files that are there.
        if (@file_put_contents($base . '/' . self::MANIFEST, json_encode(['folder' => $name, 'files' => array_keys($files)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new ThemeException('The list of published theme files could not be written.');
        }
    }

    /**
     * The CDN address of each published file, by its path inside `assets/`. Empty until the theme is published.
     *
     * @return array<string, string>
     */
    public function urls(ThemeManifest $theme, string $cdn): array
    {
        $manifest = @file_get_contents($this->folder($theme) . '/' . self::MANIFEST);
        $data = is_string($manifest) ? json_decode($manifest, true) : null;
        if (!is_array($data) || !is_string($data['folder'] ?? null) || !is_array($data['files'] ?? null)) {
            return [];
        }
        $base = $cdn . PublishedPreviews::PUBLIC_PATH . '/' . rawurlencode($theme->id) . '/' . rawurlencode($theme->version) . '/' . rawurlencode($data['folder']) . '/';
        $urls = [];
        foreach ($data['files'] as $path) {
            if (is_string($path)) {
                $urls[$path] = $base . implode('/', array_map(rawurlencode(...), explode('/', $path)));
            }
        }

        return $urls;
    }

    private function folder(ThemeManifest $theme): string
    {
        return $this->config->projectDir . '/public' . PublishedPreviews::PUBLIC_PATH . '/' . rawurlencode($theme->id) . '/' . rawurlencode($theme->version);
    }
}
