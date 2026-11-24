<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use JsonException;
use ReflectionClass;
use SightPress\Module\ModuleInterface;
use Throwable;

/**
 * Finds external modules in the project's installed Composer packages: a package declares its module class(es) in
 * `extra.sightpress.module` (a class name or a list of them) and must require `sightpress/module-interface`. It may
 * describe its optional modules for the *Features* screen in `extra.sightpress.features` ({@see ModuleAbout}).
 *
 * A package whose declaration is broken, or whose class doesn't load, is reported as unavailable rather than
 * stopping the tenant (ADR-0065). What `installed.json` declares is kept in the cache folder, when given, until the
 * file changes or `cache:clear` empties the folder, as for themes.
 */
final readonly class ComposerModuleDiscovery
{
    public const string CONTRACT_PACKAGE = 'sightpress/module-interface';

    /**
     * @param string      $installedJson path to `vendor/composer/installed.json`
     * @param string|null $cacheFile     where to keep what it declares; null reads it every time
     */
    public function __construct(private string $installedJson, private ?string $cacheFile = null)
    {
    }

    /**
     * @param string|null $cacheDir the tenant's cache folder; null in debug mode
     */
    public static function forProject(string $projectDir, ?string $cacheDir = null): self
    {
        return new self(
            rtrim($projectDir, '/') . '/vendor/composer/installed.json',
            $cacheDir === null ? null : rtrim($cacheDir, '/') . '/modules.php',
        );
    }

    /**
     * @throws ModuleException when `installed.json` itself can't be read
     */
    public function discover(): ModuleDiscovery
    {
        $candidates = [];
        $unavailable = [];
        foreach ($this->declarations() as $declaration) {
            [$found, $broken] = $this->fromDeclaration($declaration);
            array_push($candidates, ...$found);
            array_push($unavailable, ...$broken);
        }

        return new ModuleDiscovery($candidates, $unavailable);
    }

    /**
     * The `extra.sightpress` part of every package that declares a module, through the cache when there is one.
     *
     * @return list<array{name: mixed, module: mixed, require: mixed, features: mixed}>
     */
    private function declarations(): array
    {
        if (!is_file($this->installedJson)) {
            return [];
        }
        $key = (string) md5_file($this->installedJson);
        $cached = $this->cacheFile !== null && is_file($this->cacheFile) ? @include $this->cacheFile : null;
        if (is_array($cached) && ($cached['key'] ?? null) === $key && is_array($cached['modules'] ?? null)) {
            /** @var list<array{name: mixed, module: mixed, require: mixed, features: mixed}> */
            return $cached['modules'];
        }

        $declarations = $this->read();
        if ($this->cacheFile !== null) {
            @mkdir(dirname($this->cacheFile), 0o777, true);
            @file_put_contents($this->cacheFile, '<?php return ' . var_export(['key' => $key, 'modules' => $declarations], true) . ';');
        }

        return $declarations;
    }

    /**
     * @return list<array{name: mixed, module: mixed, require: mixed, features: mixed}>
     */
    private function read(): array
    {
        try {
            $installed = json_decode((string) file_get_contents($this->installedJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ModuleException(sprintf(
                'Could not read the installed packages from %s: %s. Run composer install again.',
                $this->installedJson,
                $exception->getMessage(),
            ), previous: $exception);
        }

        $packages = is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $declarations = [];
        foreach ($packages as $package) {
            $extra = is_array($package) ? ($package['extra'] ?? null) : null;
            $sightpress = is_array($extra) ? ($extra['sightpress'] ?? null) : null;
            if (is_array($package) && is_array($sightpress) && array_key_exists('module', $sightpress)) {
                $require = is_array($package['require'] ?? null) ? $package['require'] : [];
                $declarations[] = [
                    'name' => $package['name'] ?? null,
                    'module' => $sightpress['module'],
                    'require' => $require[self::CONTRACT_PACKAGE] ?? null,
                    'features' => $sightpress['features'] ?? [],
                ];
            }
        }

        return $declarations;
    }

    /**
     * @param array{name: mixed, module: mixed, require: mixed, features: mixed} $declaration
     *
     * @return array{list<ModuleCandidate>, list<UnavailableModule>}
     */
    private function fromDeclaration(array $declaration): array
    {
        $name = is_string($declaration['name']) && $declaration['name'] !== '' ? $declaration['name'] : 'an unnamed package';
        $classes = is_string($declaration['module']) ? [$declaration['module']] : $declaration['module'];
        if (!is_array($classes) || !array_is_list($classes) || $classes === []) {
            return [[], [new UnavailableModule($name, null, '"extra.sightpress.module" must be a module class name or a list of them.')]];
        }

        $constraint = $declaration['require'];
        if (!is_string($constraint) || trim($constraint) === '') {
            return [[], [new UnavailableModule($name, null, sprintf(
                'It declares a SightPress module but does not require %s. '
                . 'Add it to the package\'s "require" with the versions the module works with.',
                self::CONTRACT_PACKAGE,
            ))]];
        }

        $about = [];
        $features = $declaration['features'];
        foreach (is_array($features) ? $features : [] as $id => $entry) {
            if (is_string($id) && is_array($entry)) {
                $about[$id] = ModuleAbout::fromArray($entry, $id);
            }
        }

        $candidates = [];
        $unavailable = [];
        foreach ($classes as $class) {
            try {
                $candidates[] = new ModuleCandidate($this->instantiate($class), $name, $constraint, $about);
            } catch (Throwable $exception) {
                $unavailable[] = new UnavailableModule($name, null, $exception->getMessage());
            }
        }

        return [$candidates, $unavailable];
    }

    /**
     * @throws Throwable when the class doesn't exist, isn't a module, or fails to load or start
     */
    private function instantiate(mixed $class): ModuleInterface
    {
        if (!is_string($class) || !class_exists($class)) {
            throw new ModuleException(sprintf(
                'The module class %s does not exist. Check "extra.sightpress.module" and the package\'s autoload.',
                is_string($class) ? $class : get_debug_type($class),
            ));
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->implementsInterface(ModuleInterface::class)) {
            throw new ModuleException(sprintf('%s must implement %s.', $class, ModuleInterface::class));
        }
        if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new ModuleException(sprintf('The module %s must be a class that can be created without arguments.', $class));
        }

        /** @var ModuleInterface */
        return $reflection->newInstance();
    }
}
