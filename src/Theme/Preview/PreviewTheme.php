<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Preview;

use JsonException;
use SightPress\Core\Theme\ThemeException;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Theme\Preview\PreviewPage;
use SightPress\Theme\ThemeInterface;

/**
 * What the preview page says about a theme: its id, name, description, version and where to download it, and the
 * words that describe its look (the screenshot's alt text). Read from the theme's class when its `composer.json`
 * names one (`extra.sightpress.theme`), otherwise from `theme.json` and `composer.json`.
 */
final readonly class PreviewTheme
{
    /**
     * @param non-empty-string      $id
     * @param non-empty-string      $name
     * @param non-empty-string      $description
     * @param non-empty-string      $version
     * @param non-empty-string      $downloadUrl
     * @param non-empty-string      $directory
     * @param non-empty-string|null $previewDescription
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $version,
        public string $downloadUrl,
        public string $directory,
        public ?string $previewDescription = null,
    ) {
    }

    /**
     * @throws ThemeException when the folder holds no theme
     */
    public static function fromDirectory(string $directory, string $package = 'sightpress/theme-default'): self
    {
        $theme = new ThemeLoader()->load($directory);
        $composer = self::composer($theme->directory . '/composer.json');
        $package = is_string($composer['name'] ?? null) ? $composer['name'] : $package;
        $homepage = is_string($composer['homepage'] ?? null) ? $composer['homepage'] : null;
        $description = is_string($composer['description'] ?? null) ? trim($composer['description']) : '';
        if ($description === '') {
            $description = sprintf('The %s theme for SightPress.', $theme->label);
        }
        $extra = is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        $sightpress = is_array($extra['sightpress'] ?? null) ? $extra['sightpress'] : [];
        $class = $sightpress['theme'] ?? null;
        $manifest = is_string($class) && is_a($class, ThemeInterface::class, true) ? new $class()->manifest() : null;

        return new self(
            $theme->name,
            $manifest->name ?? $theme->label,
            $manifest->description ?? $description,
            $manifest->version ?? $theme->version,
            PreviewPage::downloadUrl($package, $homepage),
            $theme->directory,
            $manifest?->previewDescription,
        );
    }

    /**
     * The value of a preview variable ({@see PreviewPage::VARIABLES}).
     */
    public function variable(string $name): string
    {
        return match ($name) {
            'name' => $this->name,
            'description' => $this->description,
            'version' => $this->version,
            'download_url' => $this->downloadUrl,
            default => throw new ThemeException(sprintf('The preview page has no variable "theme.%s".', $name)),
        };
    }

    /**
     * @return array<mixed>
     */
    private static function composer(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ThemeException(sprintf('%s is not valid JSON: %s.', $file, $exception->getMessage()), 0, $exception);
        }

        return is_array($data) ? $data : [];
    }
}
