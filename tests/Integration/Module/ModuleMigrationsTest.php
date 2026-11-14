<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Module;

use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\Attributes\CoversNothing;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Tests\Integration\IntegrationTestCase;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\ExampleMigrations;
use SightPress\Module\ModuleRegistrar;

/**
 * Each module's migrations live in their own namespace, so two modules can use the same version number; both run
 * on SQLite in memory through the configuration core builds from the registry.
 */
#[CoversNothing]
final class ModuleMigrationsTest extends IntegrationTestCase
{
    public function testMigrationsOfEveryModuleRun(): void
    {
        $registry = ModuleRegistry::register(new ModuleLoader('0.2.0')->catalogue([
            $this->module('acme.events', 'Events'),
            $this->module('acme.media', 'Media'),
        ])->resolve(new ModuleSelection()));
        $dependencies = DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($registry)),
            new ExistingConnection($this->connection()),
        );
        $dependencies->getMetadataStorage()->ensureInitialized();

        $plan = $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
            $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
        );
        $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());

        $tables = $this->connection()->createSchemaManager()->introspectTableNames();
        $names = array_map(static fn ($name): string => $name->getUnqualifiedName()->getValue(), $tables);
        sort($names);
        self::assertSame(['acme_event', 'acme_media', MigrationConfigurationFactory::VERSIONS_TABLE], $names);
        self::assertCount(2, $dependencies->getMetadataStorage()->getExecutedMigrations()->getItems());
    }

    private function module(string $id, string $folder): ModuleCandidate
    {
        $namespace = 'SightPress\Core\Tests\Support\Migrations\\' . $folder;
        $directory = dirname(__DIR__, 2) . '/Support/Migrations/' . $folder;

        return new ModuleCandidate(new FakeModule($id, register: static function (ModuleRegistrar $r) use ($namespace, $directory): void {
            $r->migrations()->add(new ExampleMigrations($namespace, $directory));
        }), 'sightpress/core');
    }
}
