<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use FilesystemIterator;
use JsonException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SightPress\Theme\Template\CorePartial;
use SightPress\Theme\Template\RequiredTemplates;
use SightPress\Theme\ThemeInterface;
use SightPress\Theme\ThemeManifest;
use SplFileInfo;

/**
 * Everything core checks before a theme can be applied (T-1):
 *
 * - its manifest (when it has a theme class) matches `theme.json`: the same id and version;
 * - `theme.json` has the shape the schema describes ({@see ThemeLoader});
 * - every colour pair, the chart colours included, has enough contrast in every colour mode, and the base font size
 *   is at least 16 px ({@see ThemeValidator});
 * - the required templates are there, for the required layouts and every layout the manifest lists, and the shell
 *   includes core's `<head>` partial (themes can't drop meta, canonical links or JSON-LD) — the search form is one
 *   of the required templates;
 * - the package ships no screenshot or preview output (`screenshot.*`, `build/`, `theme-preview/`): screenshots are
 *   always made from the preview page (D52). Files the package leaves out (`export-ignore` in `.gitattributes`)
 *   don't count.
 *
 * Warnings: a manifest with the deprecated `screenshot`, or without a `previewDescription`.
 */
final readonly class ThemeChecker
{
    private const array PREVIEW_OUTPUT = ['build', 'theme-preview'];

    private const array SKIPPED = ['.git', 'vendor', 'node_modules'];

    public function __construct(
        private ThemeLoader $loader = new ThemeLoader(),
        private ThemeValidator $validator = new ThemeValidator(),
    ) {
    }

    /**
     * @param ThemeInterface|string $theme an installed theme, or the folder of one (its class is read from its
     *                                     `composer.json` when it names one)
     */
    public function check(ThemeInterface|string $theme): ThemeCheck
    {
        $manifest = $theme instanceof ThemeInterface ? $theme->manifest() : self::manifest($theme);
        $directory = rtrim($theme instanceof ThemeInterface ? $theme->paths()->root : $theme, '/');
        $name = $manifest->name ?? ($directory === '' ? '/' : basename($directory));
        try {
            $loaded = $this->loader->load($directory);
        } catch (ThemeException $exception) {
            return new ThemeCheck($name, [$exception->getMessage()]);
        }

        $problems = [];
        if ($manifest !== null && $manifest->id !== $loaded->name) {
            $problems[] = sprintf('theme.json is for the theme "%s", but the theme\'s manifest says "%s": use the same id in both.', $loaded->name, $manifest->id);
        }
        if ($manifest !== null && $manifest->version !== $loaded->version) {
            $problems[] = sprintf('theme.json says version %s, but the theme\'s manifest says %s: use the same version in both.', $loaded->version, $manifest->version);
        }
        $validation = $this->validator->validate($loaded);
        foreach ($validation->issues as $issue) {
            $problems[] = $issue->message;
        }
        array_push($problems, ...self::templates($directory, $manifest), ...self::previewOutput($directory));

        $warnings = [];
        if ($manifest?->screenshot !== null) {
            $warnings[] = 'The manifest names a screenshot, which is ignored and no longer allowed from theme-interface 0.3: remove it; screenshots are made from the preview page.';
        }
        if ($manifest !== null && $manifest->previewDescription === null) {
            $warnings[] = 'The manifest has no previewDescription: describe what the top of the preview page looks like, for people who can\'t see its screenshots. It is required from theme-interface 0.3.';
        }

        return new ThemeCheck($manifest->name ?? $loaded->label, $problems, $warnings, $validation->checks);
    }

    /**
     * @return list<string>
     */
    private static function templates(string $directory, ?ThemeManifest $manifest): array
    {
        $files = RequiredTemplates::files();
        foreach ($manifest->layouts ?? [] as $layout) {
            $files[] = RequiredTemplates::forLayout($layout)->file;
        }
        $problems = [];
        foreach (array_values(array_unique($files)) as $file) {
            if (!is_file($directory . '/templates/' . $file)) {
                $problems[] = sprintf('The required template templates/%s is missing.', $file);
            }
        }
        $master = $directory . '/templates/' . RequiredTemplates::MASTER;
        if (is_file($master) && preg_match('/\{%-?\s*include\s+[\'"]' . preg_quote(CorePartial::Head->value, '/') . '[\'"]/', (string) file_get_contents($master)) !== 1) {
            $problems[] = sprintf('templates/%s must include core\'s head partial inside <head>: {%% include \'%s\' %%}. It holds the description, canonical links and structured data.', RequiredTemplates::MASTER, CorePartial::Head->value);
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function previewOutput(string $directory): array
    {
        $ignored = self::exportIgnored($directory);
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($directory, $ignored): bool {
                $relative = substr($file->getPathname(), strlen($directory) + 1);

                return !in_array($relative, $ignored, true) && !in_array($file->getFilename(), self::SKIPPED, true) || !$file->isDir();
            },
        ), RecursiveIteratorIterator::SELF_FIRST);
        $problems = [];
        $found = [];
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($directory) + 1);
            $inside = array_any($found, static fn (string $folder): bool => str_starts_with($relative, $folder . '/'));
            $preview = $file->isDir() ? in_array($file->getFilename(), self::PREVIEW_OUTPUT, true) : preg_match('/^screenshot(?:-[a-z]+)?\.[a-z0-9]+$/i', $file->getFilename()) === 1;
            if ($preview && !$inside && !in_array($relative, $ignored, true)) {
                $problems[] = sprintf('Remove %s%s from the theme: a theme ships no screenshot or preview output; they are made from its preview page.', $relative, $file->isDir() ? '/' : '');
                $found[] = $relative;
            }
        }
        sort($problems);

        return $problems;
    }

    /**
     * Paths `.gitattributes` leaves out of the package (`/build export-ignore`), relative to the theme folder.
     *
     * @return list<string>
     */
    private static function exportIgnored(string $directory): array
    {
        $file = $directory . '/.gitattributes';
        $lines = is_file($file) ? (array) file($file, FILE_IGNORE_NEW_LINES) : [];
        $ignored = [];
        foreach ($lines as $line) {
            if (preg_match('#^\s*/?([^\s\#*?]+?)/?\s+export-ignore\s*$#', (string) $line, $match) === 1) {
                $ignored[] = $match[1];
            }
        }

        return $ignored;
    }

    /**
     * The manifest of the theme in a folder, when its `composer.json` names a theme class that is loaded.
     */
    private static function manifest(string $directory): ?ThemeManifest
    {
        $file = rtrim($directory, '/') . '/composer.json';
        try {
            $composer = is_file($file) ? json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $composer = null;
        }
        $extra = is_array($composer) && is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        $class = is_array($extra['sightpress'] ?? null) ? ($extra['sightpress']['theme'] ?? null) : null;

        return is_string($class) && is_a($class, ThemeInterface::class, true) ? new $class()->manifest() : null;
    }
}
