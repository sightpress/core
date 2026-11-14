<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Composer\Semver\Semver;
use SightPress\Module\Exception\InvalidValueException;
use UnexpectedValueException;

/**
 * Checks discovered modules and builds the {@see ModuleCatalogue}: every manifest must be valid, every id unique,
 * and every external module must work with the installed `sightpress/module-interface` (ADR-0009).
 */
final readonly class ModuleLoader
{
    /**
     * @param string $contractVersion installed version of `sightpress/module-interface`, for example `0.2.0`
     */
    public function __construct(private string $contractVersion)
    {
    }

    /**
     * @param list<ModuleCandidate> $candidates core modules first, then external ones
     */
    public function catalogue(array $candidates): ModuleCatalogue
    {
        $modules = [];
        foreach ($candidates as $candidate) {
            $module = $this->load($candidate);
            $id = $module->id();
            if (isset($modules[$id])) {
                throw new ModuleException(sprintf(
                    'Two modules use the id "%s": one from %s and one from %s. Remove one of them.',
                    $id,
                    $modules[$id]->source,
                    $candidate->source,
                ));
            }
            $modules[$id] = $module;
        }

        return new ModuleCatalogue($modules);
    }

    private function load(ModuleCandidate $candidate): LoadedModule
    {
        try {
            $manifest = $candidate->module->manifest();
        } catch (InvalidValueException $exception) {
            throw new ModuleException(sprintf(
                'The module %s from %s has an invalid manifest: %s',
                $candidate->module::class,
                $candidate->source,
                $exception->getMessage(),
            ), previous: $exception);
        }

        $constraint = $candidate->contractConstraint;
        // A development branch without a version alias (dev-<branch>) can't be compared with a constraint.
        if ($constraint !== null && !str_starts_with($this->contractVersion, 'dev-')) {
            try {
                $satisfied = Semver::satisfies($this->contractVersion, $constraint);
            } catch (UnexpectedValueException $exception) {
                throw new ModuleException(sprintf(
                    'Module "%s" from %s has an invalid constraint on sightpress/module-interface ("%s").',
                    $manifest->id,
                    $candidate->source,
                    $constraint,
                ), previous: $exception);
            }

            if (!$satisfied) {
                throw new ModuleException(sprintf(
                    'Module "%s" from %s works with sightpress/module-interface %s, but %s is installed. '
                    . 'Update the module, or install a version of it that works with this release of SightPress.',
                    $manifest->id,
                    $candidate->source,
                    $constraint,
                    $this->contractVersion,
                ));
            }
        }

        return new LoadedModule($candidate->module, $manifest, $candidate->source);
    }
}
