<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Tenant\TenantConfig;

/**
 * Which modules are on, for the tenant and for each site (ADR-0034).
 *
 * - Required modules are always on.
 * - The tenant may lock an optional module on or off for every site (`modules.locked` in `config/tenant.php`); a
 *   locked module can't be changed from the admin or the command line.
 * - Otherwise a site's own choice (its `site_module` table) wins; a module the site hasn't chosen for uses the
 *   tenant's default (`modules.defaults`), then the module's own (`enabledByDefault`).
 * - A module whose required modules are off for a site is off too.
 *
 * The kernel registers every installed module except those the tenant locked off, so each site can turn its own on.
 */
final readonly class ModulePolicy
{
    /**
     * @param array<string, bool>                       $defaults module id => on for new sites
     * @param array<string, bool>                       $locks    module id => on or off for every site
     * @param list<array{setting: string, id: string}>  $missing  tenant settings that name a module that isn't
     *                                                            installed or is unavailable
     */
    public function __construct(
        private ModuleCatalogue $catalogue,
        private array $defaults = [],
        private array $locks = [],
        private array $missing = [],
    ) {
    }

    /**
     * The tenant's policy, after checking that `modules.defaults` and `modules.locked` name optional modules and
     * that no module is locked on while a module it requires is locked off.
     *
     * A setting that names a module that isn't installed (its package was removed, or it is unavailable) is left out
     * and reported by `system:check` ({@see missing()}) rather than stopping the tenant.
     */
    public static function fromConfig(ModuleCatalogue $catalogue, TenantConfig $config): self
    {
        $missing = [];
        $known = [];
        foreach (['defaults' => $config->moduleDefaults, 'locked' => $config->moduleLocks] as $key => $switches) {
            foreach ($switches as $id => $on) {
                if (!$catalogue->has($id)) {
                    $missing[] = ['setting' => 'modules.' . $key, 'id' => $id];
                    continue;
                }
                if ($catalogue->get($id)->manifest->required) {
                    throw new ModuleException(sprintf(
                        'Tenant setting "modules.%s" names module "%s", which is always on. Remove it.',
                        $key,
                        $id,
                    ));
                }
                $known[$key][$id] = $on;
            }
        }
        $defaults = $known['defaults'] ?? [];
        $locks = $known['locked'] ?? [];

        foreach ($locks as $id => $on) {
            foreach ($on ? $catalogue->get($id)->requiredIds() : [] as $required) {
                if (($locks[$required] ?? true) === false) {
                    throw new ModuleException(sprintf(
                        'Tenant setting "modules.locked" turns module "%s" on but module "%s", which it requires, off. Change one of them.',
                        $id,
                        $required,
                    ));
                }
            }
        }

        return new self($catalogue, $defaults, $locks, $missing);
    }

    /**
     * Tenant settings that name a module that isn't installed or is unavailable, which `system:check` reports.
     *
     * @return list<array{setting: string, id: string}>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    public function catalogue(): ModuleCatalogue
    {
        return $this->catalogue;
    }

    /**
     * What the kernel registers: every installed module, except those the tenant locked off and the optional modules
     * that need one of them.
     */
    public function tenantSelection(): ModuleSelection
    {
        $states = [];
        foreach ($this->catalogue->ids() as $id) {
            $states[$id] = $this->locks[$id] ?? true;
        }
        do {
            $changed = false;
            foreach ($this->catalogue->ids() as $id) {
                $module = $this->catalogue->get($id);
                if (!$states[$id] || $module->manifest->required) {
                    continue;
                }
                foreach ($module->requiredIds() as $required) {
                    if (($states[$required] ?? true) === false) {
                        $states[$id] = false;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return new ModuleSelection($states);
    }

    /**
     * The tenant's lock on a module: true (on for every site), false (off) or null (each site chooses).
     */
    public function lock(string $id): ?bool
    {
        return $this->locks[$id] ?? null;
    }

    /**
     * Whether a site that hasn't chosen starts with the module on.
     */
    public function defaultFor(string $id): bool
    {
        $manifest = $this->catalogue->get($id)->manifest;

        return $manifest->required || ($this->defaults[$id] ?? $manifest->enabledByDefault);
    }

    /**
     * A site's modules, from its own choices: locks win, then the choices, then the defaults.
     *
     * @param array<string, bool> $choices module id => on, from the site's `site_module` table
     */
    public function selection(array $choices): ModuleSelection
    {
        $states = [];
        foreach ($this->catalogue->ids() as $id) {
            $states[$id] = $this->catalogue->get($id)->manifest->required
                || ($this->locks[$id] ?? $choices[$id] ?? $this->defaultFor($id));
        }

        return new ModuleSelection($states);
    }

    /**
     * Ids of the modules that are on with this selection, in the catalogue's order. A module whose required modules
     * aren't all on is left out (and so are the modules that need it), rather than stopping the site.
     *
     * @return list<string>
     */
    public function onIds(ModuleSelection $selection): array
    {
        $on = [];
        foreach ($this->catalogue->ids() as $id) {
            if ($this->catalogue->isOn($selection, $id)) {
                $on[$id] = true;
            }
        }

        do {
            $dropped = false;
            foreach (array_keys($on) as $id) {
                foreach ($this->catalogue->get($id)->requiredIds() as $required) {
                    if (!isset($on[$required])) {
                        unset($on[$id]);
                        $dropped = true;
                        break;
                    }
                }
            }
        } while ($dropped);

        return array_keys($on);
    }

    /**
     * The optional modules, which a site may turn on or off (unless the tenant locked them).
     *
     * @return list<LoadedModule>
     */
    public function optional(): array
    {
        $optional = [];
        foreach ($this->catalogue->ids() as $id) {
            $module = $this->catalogue->get($id);
            if (!$module->manifest->required) {
                $optional[] = $module;
            }
        }

        return $optional;
    }
}
