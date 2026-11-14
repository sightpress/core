<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use Composer\Semver\Semver;
use UnexpectedValueException;

/**
 * Every installed module, checked by the {@see ModuleLoader}. It decides which modules are on for a site and in
 * which order they register: a module always comes after the modules it requires (ADR-0009).
 */
final readonly class ModuleCatalogue
{
    /**
     * @param array<string, LoadedModule> $modules by id, in discovery order
     */
    public function __construct(private array $modules)
    {
    }

    public function has(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    public function get(string $id): LoadedModule
    {
        return $this->modules[$id] ?? throw new ModuleException(sprintf('Module "%s" is not installed.', $id));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->modules);
    }

    /**
     * Whether a module is on for a site: required modules always are; others follow the site's choice, or the
     * manifest's default when the site hasn't chosen.
     */
    public function isOn(ModuleSelection $selection, string $id): bool
    {
        $manifest = $this->get($id)->manifest;

        return $manifest->required || ($selection->state($id) ?? $manifest->isOnForNewSites());
    }

    /**
     * Turns a module on, after checking that every module it requires is installed and on.
     */
    public function turnOn(ModuleSelection $selection, string $id): ModuleSelection
    {
        foreach ($this->get($id)->requiredIds() as $required) {
            if (!$this->has($required)) {
                throw new ModuleException(sprintf(
                    'Module "%s" can\'t be turned on: it requires module "%s", which is not installed.',
                    $id,
                    $required,
                ));
            }
            if (!$this->isOn($selection, $required)) {
                throw new ModuleException(sprintf(
                    'Module "%s" can\'t be turned on while module "%s" is off, because it requires it. '
                    . 'Turn on "%s" first.',
                    $id,
                    $required,
                    $required,
                ));
            }
        }

        return $selection->with($id, true);
    }

    /**
     * Turns a module off. Refused for required modules and while another module that is on requires it.
     */
    public function turnOff(ModuleSelection $selection, string $id): ModuleSelection
    {
        if ($this->get($id)->manifest->required) {
            throw new ModuleException(sprintf('Module "%s" is required, so it can\'t be turned off.', $id));
        }

        foreach ($this->modules as $other) {
            if (in_array($id, $other->requiredIds(), true) && $this->isOn($selection, $other->id())) {
                throw new ModuleException(sprintf(
                    'Module "%s" can\'t be turned off while module "%s" is on, because "%s" requires it. '
                    . 'Turn off "%s" first.',
                    $id,
                    $other->id(),
                    $other->id(),
                    $other->id(),
                ));
            }
        }

        return $selection->with($id, false);
    }

    /**
     * The modules that are on for a site, each after the modules it requires.
     *
     * @return list<LoadedModule>
     */
    public function resolve(ModuleSelection $selection): array
    {
        foreach ($selection->states() as $id => $on) {
            if (!$on && $this->has($id) && $this->get($id)->manifest->required) {
                throw new ModuleException(sprintf('Module "%s" is required, so it can\'t be turned off.', $id));
            }
        }

        $on = [];
        foreach ($this->modules as $id => $module) {
            if ($this->isOn($selection, $id)) {
                $this->checkRequirements($module, $selection);
                $on[$id] = $module;
            }
        }

        return $this->sort($on);
    }

    private function checkRequirements(LoadedModule $module, ModuleSelection $selection): void
    {
        foreach ($module->manifest->requires as $requirement) {
            $required = $this->modules[$requirement->moduleId] ?? throw new ModuleException(sprintf(
                'Module "%s" requires module "%s", which is not installed. Install it, or turn "%s" off.',
                $module->id(),
                $requirement->moduleId,
                $module->id(),
            ));

            if ($module->manifest->required && !$required->manifest->required) {
                throw new ModuleException(sprintf(
                    'Module "%s" is required, so it may only require other required modules, but "%s" is optional.',
                    $module->id(),
                    $required->id(),
                ));
            }

            if (!$this->isOn($selection, $required->id())) {
                throw new ModuleException(sprintf(
                    'Module "%s" requires module "%s", which is off. Turn on "%s", or turn "%s" off.',
                    $module->id(),
                    $required->id(),
                    $required->id(),
                    $module->id(),
                ));
            }

            try {
                $satisfied = Semver::satisfies($required->manifest->version, $requirement->constraint);
            } catch (UnexpectedValueException $exception) {
                throw new ModuleException(sprintf(
                    'Module "%s" has an invalid version constraint for module "%s" ("%s").',
                    $module->id(),
                    $required->id(),
                    $requirement->constraint,
                ), previous: $exception);
            }

            if (!$satisfied) {
                throw new ModuleException(sprintf(
                    'Module "%s" requires module "%s" %s, but version %s is installed.',
                    $module->id(),
                    $required->id(),
                    $requirement->constraint,
                    $required->manifest->version,
                ));
            }
        }
    }

    /**
     * Topological sort that keeps discovery order where requirements allow it.
     *
     * @param array<string, LoadedModule> $remaining every requirement of these modules is in the array
     *
     * @return list<LoadedModule>
     */
    private function sort(array $remaining): array
    {
        $sorted = [];
        $done = [];

        while ($remaining !== []) {
            $progress = false;
            foreach ($remaining as $id => $module) {
                if (array_diff($module->requiredIds(), array_keys($done)) === []) {
                    $sorted[] = $module;
                    $done[$id] = true;
                    unset($remaining[$id]);
                    $progress = true;
                }
            }

            if (!$progress) {
                throw new ModuleException(sprintf(
                    'These modules require each other in a loop, so none of them can start: %s. '
                    . 'Remove one of the requirements.',
                    $this->loop($remaining),
                ));
            }
        }

        return $sorted;
    }

    /**
     * Follows requirements among modules that are all stuck until one repeats, for example `a → b → a`.
     *
     * @param array<string, LoadedModule> $stuck not empty; every module here requires at least one other module here
     */
    private function loop(array $stuck): string
    {
        $path = [];
        $id = (string) array_key_first($stuck);
        while (!in_array($id, $path, true)) {
            $path[] = $id;
            $next = array_values(array_intersect($stuck[$id]->requiredIds(), array_keys($stuck)));
            $id = $next[0];
        }

        $loop = array_slice($path, (int) array_search($id, $path, true));
        $loop[] = $id;

        return implode(' → ', $loop);
    }
}
