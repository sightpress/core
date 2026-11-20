<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use JsonException;
use ReflectionClass;
use SightPress\Theme\ThemeInterface;
use SightPress\ThemeDefault\DefaultTheme;

/**
 * The themes installed with Composer: every package of type `sightpress-theme` that names its theme class in
 * `extra.sightpress.theme`. The default theme (`sightpress/theme-default`, which core requires) is always there,
 * so every site has a theme to fall back on. Themes are found by their id ({@see \SightPress\Theme\ThemeManifest::$id}).
 */
final readonly class ThemeRegistry
{
    public const string PACKAGE_TYPE = 'sightpress-theme';

    /** @var array<non-empty-string, ThemeInterface> by theme id, the default theme first */
    private array $themes;

    /**
     * @param list<ThemeInterface> $themes installed themes besides the default one
     */
    public function __construct(array $themes = [])
    {
        $default = new DefaultTheme();
        $byId = [$default->manifest()->id => $default];
        foreach ($themes as $theme) {
            $byId[$theme->manifest()->id] ??= $theme;
        }
        $this->themes = $byId;
    }

    /**
     * The themes of a project, read from its `vendor/composer/installed.json`.
     *
     * @throws ThemeException when a package names a theme class that doesn't exist or isn't a theme
     */
    public static function forProject(string $projectDir): self
    {
        $file = rtrim($projectDir, '/') . '/vendor/composer/installed.json';
        try {
            $installed = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException $exception) {
            throw new ThemeException(sprintf('Could not read the installed packages from %s: %s. Run composer install again.', $file, $exception->getMessage()), 0, $exception);
        }
        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $themes = [];
        foreach ($packages as $package) {
            $class = is_array($package) && ($package['type'] ?? null) === self::PACKAGE_TYPE && is_array($package['extra'] ?? null) && is_array($package['extra']['sightpress'] ?? null)
                ? ($package['extra']['sightpress']['theme'] ?? null)
                : null;
            if ($class !== null) {
                $themes[] = self::instantiate($class, is_string($package['name'] ?? null) ? $package['name'] : 'an unnamed package');
            }
        }

        return new self($themes);
    }

    /**
     * The default theme, which every install has.
     */
    public function default(): ThemeInterface
    {
        return $this->themes[DefaultTheme::ID];
    }

    public function find(string $id): ?ThemeInterface
    {
        return $this->themes[$id] ?? null;
    }

    /**
     * @return list<ThemeInterface> the default theme first
     */
    public function all(): array
    {
        return array_values($this->themes);
    }

    private static function instantiate(mixed $class, string $package): ThemeInterface
    {
        if (!is_string($class) || !class_exists($class)) {
            throw new ThemeException(sprintf('Package %s names a theme class that does not exist: %s.', $package, is_string($class) ? $class : get_debug_type($class)));
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->implementsInterface(ThemeInterface::class) || !$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new ThemeException(sprintf('Package %s: %s must be a class that implements %s and can be created without arguments.', $package, $class, ThemeInterface::class));
        }

        /** @var ThemeInterface */
        return $reflection->newInstance();
    }
}
