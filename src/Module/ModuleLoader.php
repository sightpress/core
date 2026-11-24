<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Composer\Semver\Semver;
use SightPress\Module\Exception\InvalidValueException;
use Throwable;
use UnexpectedValueException;

/**
 * Checks discovered modules and builds the {@see ModuleCatalogue}: every manifest must be valid, every id unique,
 * every external module must work with the installed `sightpress/module-interface` (ADR-0009), and every module it
 * requires must be installed in a version it accepts.
 *
 * A problem with one of core's own modules stops the tenant, as it is a broken install. A problem with an external
 * module leaves that module (and the modules that need it) out as unavailable, so every site keeps working
 * (ADR-0065).
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
     * @param list<ModuleCandidate>   $candidates  core modules first, then external ones
     * @param list<UnavailableModule> $unavailable external modules discovery already found broken
     *
     * @throws ModuleException when one of core's modules has a problem
     */
    public function catalogue(array $candidates, array $unavailable = []): ModuleCatalogue
    {
        $modules = [];
        foreach ($candidates as $candidate) {
            try {
                $module = $this->load($candidate);
                $id = $module->id();
                if (isset($modules[$id])) {
                    throw self::about($id, new ModuleException(sprintf(
                        'Two modules use the id "%s": one from %s and one from %s. Remove one of them.',
                        $id,
                        $modules[$id]->source,
                        $candidate->source,
                    )));
                }
                $modules[$id] = $module;
            } catch (ModuleException $exception) {
                if ($candidate->contractConstraint === null) {
                    throw $exception;
                }
                $unavailable[] = new UnavailableModule($candidate->source, $exception->moduleId, $exception->getMessage());
            }
        }

        [$modules, $unavailable] = $this->withRequirementsMet($modules, $unavailable);

        return new ModuleCatalogue($modules, $unavailable);
    }

    /**
     * Leaves out the external modules that need a module that isn't installed, or isn't installed in a version they
     * accept, until every module left has what it needs.
     *
     * @param array<string, LoadedModule> $modules
     * @param list<UnavailableModule>     $unavailable
     *
     * @return array{array<string, LoadedModule>, list<UnavailableModule>}
     */
    private function withRequirementsMet(array $modules, array $unavailable): array
    {
        do {
            $dropped = false;
            foreach ($modules as $id => $module) {
                $problem = $module->external ? $this->missingRequirement($module, $modules) : null;
                if ($problem !== null) {
                    $unavailable[] = new UnavailableModule($module->source, $module->id(), $problem);
                    unset($modules[$id]);
                    $dropped = true;
                }
            }
        } while ($dropped);

        // Modules that need each other in a loop can never start: leave the external ones out (a loop among core's
        // modules stops the tenant when they are resolved).
        $started = [];
        do {
            $progress = false;
            foreach ($modules as $id => $module) {
                if (!isset($started[$id]) && array_diff($module->requiredIds(), array_keys($started)) === []) {
                    $started[$id] = true;
                    $progress = true;
                }
            }
        } while ($progress);
        foreach (array_diff_key($modules, $started) as $id => $module) {
            if ($module->external) {
                $unavailable[] = new UnavailableModule($module->source, $module->id(), 'It needs modules that need it in turn, in a loop, so none of them can start.');
                unset($modules[$id]);
            }
        }

        return [$modules, $unavailable];
    }

    /**
     * @param array<string, LoadedModule> $modules
     */
    private function missingRequirement(LoadedModule $module, array $modules): ?string
    {
        foreach ($module->manifest->requires as $requirement) {
            $required = $modules[$requirement->moduleId] ?? null;
            if ($required === null) {
                return sprintf('It needs module "%s", which is not installed or is unavailable.', $requirement->moduleId);
            }
            if ($module->manifest->required && !$required->manifest->required) {
                return sprintf('It is always on, so it may only need modules that are always on, but "%s" is optional.', $requirement->moduleId);
            }
            try {
                $met = Semver::satisfies($required->manifest->version, $requirement->constraint);
            } catch (UnexpectedValueException) {
                return sprintf('It has an invalid version constraint for module "%s" ("%s").', $requirement->moduleId, $requirement->constraint);
            }
            if (!$met) {
                return sprintf(
                    'It needs module "%s" %s, but version %s is installed.',
                    $requirement->moduleId,
                    $requirement->constraint,
                    $required->manifest->version,
                );
            }
        }

        return null;
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
        } catch (Throwable $exception) {
            if ($candidate->contractConstraint === null) {
                throw $exception;
            }
            throw new ModuleException(sprintf(
                'The module %s from %s failed to give its manifest: %s',
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
                throw self::about($manifest->id, new ModuleException(sprintf(
                    'Module "%s" from %s has an invalid constraint on sightpress/module-interface ("%s").',
                    $manifest->id,
                    $candidate->source,
                    $constraint,
                ), previous: $exception));
            }

            if (!$satisfied) {
                throw self::about($manifest->id, new ModuleException(sprintf(
                    'Module "%s" from %s works with sightpress/module-interface %s, but %s is installed. '
                    . 'Update the module, or install a version of it that works with this release of SightPress.',
                    $manifest->id,
                    $candidate->source,
                    $constraint,
                    $this->contractVersion,
                )));
            }
        }

        return new LoadedModule($candidate->module, $manifest, $candidate->source, $candidate->about[$manifest->id] ?? null, $constraint !== null);
    }

    /**
     * @param non-empty-string $id
     */
    private static function about(string $id, ModuleException $exception): ModuleException
    {
        $exception->moduleId = $id;

        return $exception;
    }
}
