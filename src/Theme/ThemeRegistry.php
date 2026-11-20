<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use JsonException;
use ReflectionClass;
use SightPress\Theme\ThemeInterface;
use SightPress\ThemeDefault\DefaultTheme;
use Throwable;

/**
 * The themes installed with Composer: every package of type `sightpress-theme` that names its theme class in
 * `extra.sightpress.theme`. The default theme (`sightpress/theme-default`, which core requires) is always there,
 * so every site has a theme to fall back on. Themes are found by their id ({@see \SightPress\Theme\ThemeManifest::$id}).
 *
 * A theme whose `theme-interface` constraint the installed contract doesn't meet is listed but unavailable, with the
 * reason ({@see unavailable()}). What `forProject()` finds is kept in the cache folder until `installed.json` changes
 * or `cache:clear` empties it.
 */
final readonly class ThemeRegistry
{
    public const string PACKAGE_TYPE = 'sightpress-theme';

    public const string CONTRACT_PACKAGE = 'sightpress/theme-interface';

    /** @var array<non-empty-string, ThemeInterface> by theme id, the default theme first */
    private array $themes;

    /** @var array<non-empty-string, non-empty-string> package name by theme id, where known */
    private array $packages;

    /**
     * @param list<ThemeInterface>         $themes          installed themes; the default theme is added when none
     *                                                      of them has its id
     * @param array<string, string>        $packages        package name by theme class
     * @param string|null                  $contractVersion installed version of `sightpress/theme-interface`
     *                                                      (null: the one Composer installed)
     */
    public function __construct(array $themes = [], array $packages = [], private ?string $contractVersion = null)
    {
        $byId = [];
        $names = [];
        foreach ($themes as $theme) {
            $id = $theme->manifest()->id;
            if (!isset($byId[$id])) {
                $byId[$id] = $theme;
                $package = $packages[$theme::class] ?? '';
                if ($package !== '') {
                    $names[$id] = $package;
                }
            }
        }
        // The default theme is always there, first; a theme given with its id (a checkout being previewed) stands in.
        $byId = [DefaultTheme::ID => $byId[DefaultTheme::ID] ?? new DefaultTheme()] + $byId;
        $names[DefaultTheme::ID] ??= 'sightpress/theme-default';
        $this->themes = $byId;
        $this->packages = $names;
    }

    /**
     * The themes of a project, read from its `vendor/composer/installed.json`, through the cache folder when given.
     *
     * @throws ThemeException when a package names a theme class that doesn't exist or isn't a theme
     */
    public static function forProject(string $projectDir, ?string $cacheDir = null): self
    {
        $file = rtrim($projectDir, '/') . '/vendor/composer/installed.json';
        $key = is_file($file) ? md5_file($file) : 'none';
        $cache = $cacheDir === null ? null : rtrim($cacheDir, '/') . '/themes.php';
        $cached = $cache !== null && is_file($cache) ? @include $cache : null;
        $found = is_array($cached) && ($cached['key'] ?? null) === $key && is_array($cached['themes'] ?? null) ? $cached['themes'] : self::read($file);

        $themes = [];
        $packages = [];
        foreach ($found as $class => $package) {
            $class = is_string($class) ? $class : '';
            $package = is_string($package) ? $package : 'an unnamed package';
            $themes[] = self::instantiate($class, $package);
            $packages[$class] = $package;
        }
        if ($cache !== null && $cached !== ['key' => $key, 'themes' => $found]) {
            @mkdir(dirname($cache), 0o777, true);
            @file_put_contents($cache, '<?php return ' . var_export(['key' => $key, 'themes' => $found], true) . ';');
        }

        return new self($themes, $packages);
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

    /**
     * The Composer package of an installed theme, when known.
     */
    public function package(string $id): ?string
    {
        return $this->packages[$id] ?? null;
    }

    /**
     * Why a theme can't be used with this version of the contract, or null when it can.
     */
    public function unavailable(ThemeInterface $theme): ?string
    {
        $constraint = $theme->manifest()->requires->constraint;
        try {
            // Composer's own check knows the branch aliases of a development checkout (dev-main as 0.2.x-dev).
            $met = $this->contractVersion === null
                ? InstalledVersions::satisfies(new VersionParser(), self::CONTRACT_PACKAGE, $constraint)
                : Semver::satisfies($this->contractVersion, $constraint);
        } catch (Throwable) {
            $met = false;
        }
        $installed = $this->contractVersion ?? (InstalledVersions::getPrettyVersion(self::CONTRACT_PACKAGE) ?? 'none');

        return $met ? null : sprintf(
            'It needs %s %s, and this SightPress has %s.',
            self::CONTRACT_PACKAGE,
            $constraint,
            preg_replace('/^(\d+\.\d+)\.9999999\.9999999-dev$/', '$1.x-dev', $installed),
        );
    }

    /**
     * @return array<string, string> package name by theme class, from `installed.json`
     */
    private static function read(string $file): array
    {
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
            $name = is_array($package) && is_string($package['name'] ?? null) ? $package['name'] : 'an unnamed package';
            if ($class !== null) {
                $themes[is_string($class) ? $class : ''] = $name;
            }
        }

        return $themes;
    }

    private static function instantiate(string $class, string $package): ThemeInterface
    {
        if ($class === '' || !class_exists($class)) {
            throw new ThemeException(sprintf('Package %s names a theme class that does not exist: %s.', $package, $class === '' ? 'not a class name' : $class));
        }
        $reflection = new ReflectionClass($class);
        if (!$reflection->implementsInterface(ThemeInterface::class) || !$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new ThemeException(sprintf('Package %s: %s must be a class that implements %s and can be created without arguments.', $package, $class, ThemeInterface::class));
        }

        /** @var ThemeInterface */
        return $reflection->newInstance();
    }
}
