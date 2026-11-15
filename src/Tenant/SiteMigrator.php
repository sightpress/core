<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Module\ModuleRegistry;

/**
 * Runs the migrations of every module that is on against one site's database, in version order. Each migration runs
 * in a transaction where the engine allows it.
 */
final readonly class SiteMigrator
{
    public function __construct(
        private SiteContainerFactory $containers,
        private ModuleRegistry $modules,
    ) {
    }

    /**
     * Runs the migrations the site's database doesn't have yet. Only this creates the table that records them:
     * {@see pending()} and {@see unknown()} leave the database as it is.
     *
     * @return list<string> the versions that ran, oldest first
     */
    public function migrate(Site $site): array
    {
        $dependencies = $this->dependencies($site);
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
     * How many migrations the site's database doesn't have yet.
     */
    public function pending(Site $site): int
    {
        return count($this->dependencies($site)->getMigrationStatusCalculator()->getNewMigrations());
    }

    /**
     * How many migrations the site's database ran that no module installed here has: the database belongs to a
     * newer version, or to a module that isn't installed.
     */
    public function unknown(Site $site): int
    {
        return count($this->dependencies($site)->getMigrationStatusCalculator()->getExecutedUnavailableMigrations());
    }

    private function dependencies(Site $site): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($this->modules)),
            new ExistingConnection(Services::get($this->containers->forSite($site), Connection::class)),
        );
    }
}
