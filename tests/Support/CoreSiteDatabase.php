<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use SightPress\Core\Access\PermissionCatalogue;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleLoader;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\ModuleSelection;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Rbac\RegistrationRole;
use SightPress\Core\Rbac\RoleManager;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Site\DbalSiteSettings;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A site database for tests: SQLite in memory with every core module's migrations run and an entity manager that
 * maps core's entities. Optionally seeded with the RBAC catalogue.
 */
final class CoreSiteDatabase
{
    public readonly Connection $connection;

    public readonly EntityManagerInterface $entityManager;

    public readonly ModuleRegistry $registry;

    private ?DbalSiteSettings $settings = null;

    /**
     * @param list<ModuleCandidate> $extraModules modules to load next to core's (for example ones adding permissions)
     */
    public function __construct(array $extraModules = [], bool $migrate = true)
    {
        $this->registry = ModuleRegistry::register(
            new ModuleLoader('0.2.0')
                ->catalogue([...CoreModules::candidates(), ...$extraModules])
                ->resolve(new ModuleSelection()),
        );
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        if ($migrate) {
            $this->migrate();
        }

        $orm = ORMSetup::createAttributeMetadataConfig(CoreModules::entityPaths(), true, null, new ArrayAdapter());
        $orm->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager($this->connection, $orm);
    }

    public function dependencies(): DependencyFactory
    {
        return DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($this->registry)),
            new ExistingConnection($this->connection),
        );
    }

    public function migrate(string $version = 'latest'): void
    {
        $dependencies = $this->dependencies();
        $dependencies->getMetadataStorage()->ensureInitialized();
        $plan = $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
            $dependencies->getVersionAliasResolver()->resolveVersionAlias($version),
        );
        $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    public function catalogue(): PermissionCatalogue
    {
        return PermissionCatalogue::fromRegistry($this->registry);
    }

    public function settings(): DbalSiteSettings
    {
        return $this->settings ??= new DbalSiteSettings($this->connection);
    }

    public function roles(): RoleRepository
    {
        return new RoleRepository($this->entityManager);
    }

    public function registrationRole(): RegistrationRole
    {
        return new RegistrationRole($this->settings(), $this->roles());
    }

    public function manager(): RoleManager
    {
        return new RoleManager($this->roles(), $this->registrationRole());
    }

    public function seeder(): RbacSeeder
    {
        return new RbacSeeder($this->catalogue(), $this->roles(), $this->manager(), $this->settings());
    }

    /**
     * @return list<string> table names, sorted
     */
    public function tables(): array
    {
        $names = array_map(
            static fn ($name): string => $name->getUnqualifiedName()->getValue(),
            $this->connection->createSchemaManager()->introspectTableNames(),
        );
        sort($names);

        return $names;
    }
}
