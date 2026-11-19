<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Module\ModuleRegistry;

/**
 * Runs the migrations of the modules that are on for a site against its database, in version order. Each migration
 * runs in a transaction where the engine allows it. A module that is off for the site has its migrations run when
 * the site turns it on (ADR-0034).
 */
final readonly class SiteMigrator
{
    /**
     * @param ModuleRegistry $modules what every module the tenant loaded registered
     */
    public function __construct(
        private SiteContainerFactory $containers,
        private ModuleRegistry $modules,
    ) {
    }

    /**
     * Runs the migrations the site's database doesn't have yet. Only this creates the table that records them:
     * {@see pending()} and {@see unknown()} leave the database as it is.
     *
     * @param ModuleRegistry|null $modules the modules to migrate for; null for those that are on for the site
     *
     * @return list<string> the versions that ran, oldest first
     */
    public function migrate(Site $site, ?ModuleRegistry $modules = null): array
    {
        $dependencies = $this->dependencies($site, $modules ?? $this->siteModules($site));
        $dependencies->getMetadataStorage()->ensureInitialized();
        $plan = $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
            $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
        );
        $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());

        $versions = [];
        foreach ($plan->getItems() as $item) {
            $versions[] = (string) $item->getVersion();
        }

        return $versions;
    }

    /**
     * How many migrations of the modules that are on for the site its database doesn't have yet.
     */
    public function pending(Site $site): int
    {
        return count($this->dependencies($site, $this->siteModules($site))->getMigrationStatusCalculator()->getNewMigrations());
    }

    /**
     * How many migrations the site's database ran that no module installed here has: the database belongs to a
     * newer version, or to a module that isn't installed.
     */
    public function unknown(Site $site): int
    {
        return count($this->dependencies($site, $this->modules)->getMigrationStatusCalculator()->getExecutedUnavailableMigrations());
    }

    /**
     * Deletes a module's data from the site's database: undoes the module's migrations that ran, newest first, so
     * its tables are dropped and turning it on again starts afresh.
     *
     * @return list<string> the versions that were undone, newest first
     */
    public function purge(Site $site, string $module): array
    {
        $dependencies = $this->dependencies($site, $this->modules->only([$module]));
        $versions = array_reverse($this->ran($dependencies));
        if ($versions === []) {
            return [];
        }

        $plan = $dependencies->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::DOWN);
        $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());

        return array_map(strval(...), $versions);
    }

    /**
     * Whether the site's database holds tables of the module: some of its migrations ran.
     */
    public function hasData(Site $site, string $module): bool
    {
        return $this->ran($this->dependencies($site, $this->modules->only([$module]))) !== [];
    }

    /**
     * Runs the migrations of the given modules (and nothing else) that the site's database doesn't have yet, for
     * example before a module is turned on.
     *
     * @param list<string> $modules ids of the modules that will be on
     *
     * @return list<string> the versions that ran, oldest first
     */
    public function migrateFor(Site $site, array $modules): array
    {
        return $this->migrate($site, $this->modules->only($modules));
    }

    /**
     * The versions of the configured migrations the database ran, oldest first.
     *
     * @return list<Version>
     */
    private function ran(DependencyFactory $dependencies): array
    {
        if ($dependencies->getConfiguration()->getMigrationDirectories() === []) {
            return [];
        }
        $dependencies->getMetadataStorage()->ensureInitialized();
        $executed = $dependencies->getMetadataStorage()->getExecutedMigrations();
        $versions = [];
        foreach ($dependencies->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            if ($executed->hasMigration($migration->getVersion())) {
                $versions[] = $migration->getVersion();
            }
        }

        return $versions;
    }

    private function siteModules(Site $site): ModuleRegistry
    {
        return Services::get($this->containers->forSite($site), ModuleRegistry::class);
    }

    private function dependencies(Site $site, ModuleRegistry $modules): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($modules)),
            new ExistingConnection(Services::get($this->containers->forSite($site), Connection::class)),
        );
    }
}
