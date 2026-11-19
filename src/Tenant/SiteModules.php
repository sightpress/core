<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Module\LoadedModule;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Module\SiteModuleStore;
use SightPress\Core\Template\Message;

/**
 * Each site's optional modules ("features"), as its admins turn them on and off on the *Features* screen or with
 * `site:module:*` (ADR-0034).
 *
 * - Turning one on runs its migrations first; refused while a module it needs is off.
 * - Turning one off is refused while a module that is on needs it; its data stays.
 * - Its data is deleted only on request, and only while it is off.
 * - A module the tenant locked ("Set by your organisation") can't be changed.
 *
 * Every change is stored with when and by whom, announced with {@see SiteModuleChanged} (audit log, new
 * permissions) and evicts the site's container, so what comes next sees the change.
 */
final readonly class SiteModules
{
    public function __construct(
        private ModulePolicy $policy,
        private SiteMigrator $migrator,
        private SiteContainerFactory $containers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Every optional module, in the order the modules load.
     *
     * @return list<SiteModuleState>
     */
    public function all(Site $site): array
    {
        $on = array_fill_keys($this->onIds($this->selection($site)), true);

        return array_map(fn (LoadedModule $module): SiteModuleState => $this->state($module, $on), $this->policy->optional());
    }

    /**
     * @throws SiteModuleException when no optional module has that id
     */
    public function get(Site $site, string $id): SiteModuleState
    {
        return $this->state($this->module($id), array_fill_keys($this->onIds($this->selection($site)), true));
    }

    /**
     * Whether the site's database holds the module's tables, which deleting its data would drop.
     */
    public function hasData(Site $site, string $id): bool
    {
        return $this->migrator->hasData($site, $this->module($id)->id());
    }

    /**
     * Turns a module on, after running its migrations.
     *
     * @return bool false when it was already on
     *
     * @throws SiteModuleException when it is locked, or a module it needs is off
     */
    public function turnOn(Site $site, string $id, ?int $actorId): bool
    {
        $module = $this->changeable($id);
        $selection = $this->selection($site);
        $on = $this->onIds($selection);
        if (in_array($id, $on, true)) {
            return false;
        }

        foreach ($module->requiredIds() as $required) {
            if (!in_array($required, $on, true)) {
                throw new SiteModuleException(new Message(
                    '{name} needs {required}, which is off. Turn on {required} first.',
                    ['name' => $module->name(), 'required' => $this->name($required)],
                ));
            }
        }

        $this->migrator->migrateFor($site, $this->onIds($selection->with($id, true)));
        $this->save($site, $id, true, SiteModuleChanged::ENABLED, $module, $actorId);

        return true;
    }

    /**
     * Turns a module off; its data stays.
     *
     * @return bool false when it was already off
     *
     * @throws SiteModuleException when it is locked, or a module that is on needs it
     */
    public function turnOff(Site $site, string $id, ?int $actorId): bool
    {
        $module = $this->changeable($id);
        $on = $this->onIds($this->selection($site));
        if (!in_array($id, $on, true)) {
            return false;
        }

        $neededBy = $this->neededBy($id, $on);
        if ($neededBy !== []) {
            throw new SiteModuleException(new Message(
                '{needer} needs {name}, so {name} can’t be turned off while {needer} is on. Turn off {needer} first.',
                ['name' => $module->name(), 'needer' => implode(', ', $neededBy)],
            ));
        }

        $this->save($site, $id, false, SiteModuleChanged::DISABLED, $module, $actorId);

        return true;
    }

    /**
     * Deletes the module's data from the site's database (its tables), for good.
     *
     * @return int how many of its migrations were undone; 0 when it had no data
     *
     * @throws SiteModuleException when it is locked or still on
     */
    public function deleteData(Site $site, string $id, ?int $actorId): int
    {
        $module = $this->changeable($id);
        if (in_array($id, $this->onIds($this->selection($site)), true)) {
            throw new SiteModuleException(new Message('Turn off {name} before you delete its data.', ['name' => $module->name()]));
        }

        $undone = count($this->migrator->purge($site, $id));
        if ($undone > 0) {
            $this->events($site)->dispatch(new SiteModuleChanged($id, $module->name(), SiteModuleChanged::DATA_DELETED, $actorId));
            $this->containers->evict($site->slug);
        }

        return $undone;
    }

    /**
     * Checks the modules a new site should start without (`site:create --without`): each must be optional and not
     * locked on by the tenant.
     *
     * @param list<string> $ids
     *
     * @throws SiteModuleException
     */
    public function checkLeftOff(array $ids): void
    {
        $optional = array_map(static fn (LoadedModule $module): string => $module->id(), $this->policy->optional());
        foreach ($ids as $id) {
            if (!in_array($id, $optional, true)) {
                throw new SiteModuleException(new Message(
                    $optional === []
                        ? '“{id}” is not an optional module. This install has none: every module is always on.'
                        : '“{id}” is not an optional module. Choose from: {modules}.',
                    ['id' => $id, 'modules' => implode(', ', $optional)],
                ));
            }
            if ($this->policy->lock($id) === true) {
                throw new SiteModuleException(new Message(
                    '“{id}” is set by your organisation to be on for every site.',
                    ['id' => $id],
                ));
            }
        }
    }

    /**
     * Gets a new site's database ready: stores its choice for every optional module (off for those left off,
     * otherwise the default, so a later change of the tenant's defaults doesn't change it) and runs the migrations
     * of the modules that are on.
     *
     * @param list<string> $leftOff ids of optional modules the site starts without, checked by {@see checkLeftOff()}
     *
     * @return list<string> the migration versions that ran, oldest first
     */
    public function start(Site $site, array $leftOff = []): array
    {
        $choices = [];
        foreach ($this->policy->optional() as $module) {
            $choices[$module->id()] = !in_array($module->id(), $leftOff, true) && $this->policy->defaultFor($module->id());
        }

        $versions = $this->migrator->migrateFor($site, $this->onIds($this->policy->selection($choices)));
        $store = Services::get($this->containers->forSite($site), SiteModuleStore::class);
        foreach ($choices as $id => $on) {
            $store->save($id, $on, $this->clock->now(), null);
        }
        $this->containers->evict($site->slug);

        return $versions;
    }

    /**
     * @param SiteModuleChanged::ENABLED|SiteModuleChanged::DISABLED $change
     */
    private function save(Site $site, string $id, bool $on, string $change, LoadedModule $module, ?int $actorId): void
    {
        Services::get($this->containers->forSite($site), SiteModuleStore::class)->save($id, $on, $this->clock->now(), $actorId);
        $this->events($site)->dispatch(new SiteModuleChanged($id, $module->name(), $change, $actorId));
        $this->containers->evict($site->slug);
    }

    private function events(Site $site): EventDispatcherInterface
    {
        return Services::get($this->containers->forSite($site), EventDispatcherInterface::class);
    }

    /**
     * @throws SiteModuleException when it isn't an optional module, or the tenant locked it
     */
    private function changeable(string $id): LoadedModule
    {
        $module = $this->module($id);
        if ($this->policy->lock($id) !== null) {
            throw new SiteModuleException(new Message(
                '{name} is set by your organisation, so it can’t be changed for this site.',
                ['name' => $module->name()],
            ));
        }

        return $module;
    }

    /**
     * @throws SiteModuleException when no optional module has that id
     */
    private function module(string $id): LoadedModule
    {
        $catalogue = $this->policy->catalogue();
        if (!$catalogue->has($id) || $catalogue->get($id)->manifest->required) {
            throw new SiteModuleException(new Message(
                'There is no optional feature “{id}”.',
                ['id' => $id],
            ));
        }

        return $catalogue->get($id);
    }

    private function selection(Site $site): ModuleSelection
    {
        return $this->policy->selection(Services::get($this->containers->forSite($site), SiteModuleStore::class)->choices());
    }

    /**
     * @return list<string>
     */
    private function onIds(ModuleSelection $selection): array
    {
        return $this->policy->onIds($selection);
    }

    /**
     * @param array<string, true> $on
     */
    private function state(LoadedModule $module, array $on): SiteModuleState
    {
        $id = $module->id();

        return new SiteModuleState(
            $id,
            $module->name(),
            $module->about->description ?? '',
            $module->about->whenOff ?? '',
            isset($on[$id]),
            $this->policy->lock($id) !== null,
            array_map($this->name(...), $module->requiredIds()),
            $this->neededBy($id, array_keys($on)),
        );
    }

    /**
     * Names of the modules that are on and need this one.
     *
     * @param list<string> $on
     *
     * @return list<string>
     */
    private function neededBy(string $id, array $on): array
    {
        $catalogue = $this->policy->catalogue();
        $names = [];
        foreach ($on as $other) {
            if (in_array($id, $catalogue->get($other)->requiredIds(), true)) {
                $names[] = $catalogue->get($other)->name();
            }
        }

        return $names;
    }

    private function name(string $id): string
    {
        $catalogue = $this->policy->catalogue();

        return $catalogue->has($id) ? $catalogue->get($id)->name() : $id;
    }
}
