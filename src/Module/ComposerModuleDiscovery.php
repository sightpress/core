<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use JsonException;
use ReflectionClass;
use SightPress\Module\ModuleInterface;

/**
 * Finds external modules in the project's installed Composer packages: a package declares its module class(es) in
 * `extra.sightpress.module` (a class name or a list of them) and must require `sightpress/module-interface`.
 */
final readonly class ComposerModuleDiscovery
{
    public const string CONTRACT_PACKAGE = 'sightpress/module-interface';

    /**
     * @param string $installedJson path to `vendor/composer/installed.json`
     */
    public function __construct(private string $installedJson)
    {
    }

    public static function forProject(string $projectDir): self
    {
        return new self(rtrim($projectDir, '/') . '/vendor/composer/installed.json');
    }

    /**
     * @return list<ModuleCandidate>
     */
    public function candidates(): array
    {
        if (!is_file($this->installedJson)) {
            return [];
        }

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
        $candidates = [];
        foreach ($packages as $package) {
            if (is_array($package)) {
                array_push($candidates, ...$this->fromPackage($package));
            }
        }

        return $candidates;
    }

    /**
     * @param array<mixed> $package
     *
     * @return list<ModuleCandidate>
     */
    private function fromPackage(array $package): array
    {
        $extra = $package['extra'] ?? null;
        $sightpress = is_array($extra) ? ($extra['sightpress'] ?? null) : null;
        $classes = is_array($sightpress) ? ($sightpress['module'] ?? null) : null;
        if ($classes === null) {
            return [];
        }

        $name = is_string($package['name'] ?? null) && $package['name'] !== '' ? $package['name'] : 'an unnamed package';
        $classes = is_string($classes) ? [$classes] : $classes;
        if (!is_array($classes) || !array_is_list($classes) || $classes === []) {
            throw new ModuleException(sprintf(
                'Package %s: "extra.sightpress.module" must be a module class name or a list of them.',
                $name,
            ));
        }

        $require = is_array($package['require'] ?? null) ? $package['require'] : [];
        $constraint = $require[self::CONTRACT_PACKAGE] ?? null;
        if (!is_string($constraint) || trim($constraint) === '') {
            throw new ModuleException(sprintf(
                'Package %s declares a SightPress module but does not require %s. '
                . 'Add it to the package\'s "require" with the versions the module works with.',
                $name,
                self::CONTRACT_PACKAGE,
            ));
        }

        $candidates = [];
        foreach ($classes as $class) {
            $candidates[] = new ModuleCandidate($this->instantiate($class, $name), $name, $constraint);
        }

        return $candidates;
    }

    /**
     * @param non-empty-string $package
     */
    private function instantiate(mixed $class, string $package): ModuleInterface
    {
        if (!is_string($class) || !class_exists($class)) {
            throw new ModuleException(sprintf(
                'Package %s names a module class that does not exist: %s.',
                $package,
                is_string($class) ? $class : get_debug_type($class),
            ));
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->implementsInterface(ModuleInterface::class)) {
            throw new ModuleException(sprintf(
                'Package %s: %s must implement %s.',
                $package,
                $class,
                ModuleInterface::class,
            ));
        }
        if (!$reflection->isInstantiable() || ($reflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0) {
            throw new ModuleException(sprintf(
                'Package %s: module %s must be a class that can be created without arguments.',
                $package,
                $class,
            ));
        }

        /** @var ModuleInterface */
        return $reflection->newInstance();
    }
}
