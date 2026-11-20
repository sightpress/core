<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use SightPress\Core\Template\TemplatePaths;

/**
 * Finds a theme's static files (`assets/` of the site theme, then of the default theme, then core's own files for the
 * admin panel) and builds `tokens.css` from the active theme. Under `admin/` it serves the default theme's files and
 * tokens instead, whatever the site's theme: the admin panel always looks the same and keeps its contrast. Only known
 * file types inside an `assets/` folder are served; anything else is not found.
 */
final readonly class ThemeAssets
{
    public const string TOKENS = 'tokens.css';

    /** Prefix of the admin panel's files: the default theme's, never the site theme's. */
    public const string ADMIN = 'admin/';

    private const array TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/vnd.microsoft.icon',
        'woff2' => 'font/woff2',
    ];

    public function __construct(
        private Theme $theme,
        private TemplatePaths $paths,
        private TokenStylesheet $stylesheet,
        private ThemeLoader $loader = new ThemeLoader(),
    ) {
    }

    /**
     * @param string $path relative path inside `assets/`, for example `css/site.css`
     */
    public function find(string $path): ?ThemeAsset
    {
        $admin = str_starts_with($path, self::ADMIN);
        $path = $admin ? substr($path, strlen(self::ADMIN)) : $path;
        if ($path === self::TOKENS) {
            $tokens = $admin ? $this->loader->load(TemplatePaths::defaultTheme())->tokens : $this->theme->tokens;

            return new ThemeAsset($this->stylesheet->compile($tokens), self::TYPES['css']);
        }

        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($type === null || preg_match('#^(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*$#', $path) !== 1) {
            return null;
        }

        $themes = $admin ? [TemplatePaths::defaultTheme()] : $this->paths->themes;
        foreach ([...array_map(static fn (string $theme): string => $theme . '/assets', $themes), TemplatePaths::coreAssets()] as $folder) {
            $root = realpath($folder);
            $file = realpath($folder . '/' . $path);
            if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
                continue;
            }
            $contents = file_get_contents($file);
            if ($contents !== false) {
                return new ThemeAsset($contents, $type);
            }
        }

        return null;
    }
}
