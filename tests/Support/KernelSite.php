<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Rbac\RbacSeeder;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;

/**
 * A tenant project with one site (`demo` on `example.org`) booted through the whole kernel, its database on a SQLite
 * file (or the DB matrix server), migrated and seeded. Files go to the project's `sites/` folder.
 */
final class KernelSite
{
    public readonly TestProject $project;

    public readonly Kernel $kernel;

    /**
     * @param list<string> $domains
     * @param list<string> $locales the first one is the default
     */
    public function __construct(string $name, array $domains = ['example.org'], string $environment = 'test', array $locales = ['en-GB'])
    {
        $this->project = new TestProject();
        $dsn = TestDatabases::dsn($name, $this->project->path());
        $this->project->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 1,
            'sites' => ['demo' => SiteFixture::entry('demo', [
                'domains' => $domains,
                'default_locale' => $locales[0],
                'locales' => $locales,
                'db_dsn' => SiteFixture::secretBox()->encrypt($dsn),
            ])],
        ], true) . ';');
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment, 'APP_SECRET' => SiteFixture::SECRET]),
            CoreModules::candidates(),
        );

        $container = $this->container();
        $dependencies = DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($this->kernel->modules())),
            new ExistingConnection(Services::get($container, Connection::class)),
        );
        $dependencies->getMetadataStorage()->ensureInitialized();
        $dependencies->getMigrator()->migrate(
            $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion(
                $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest'),
            ),
            new MigratorConfiguration(),
        );
        Services::get($container, RbacSeeder::class)->seed();
    }

    public function site(): Site
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find('demo');
        \assert($site !== null);

        return $site;
    }

    /**
     * The demo site's container.
     */
    public function container(): ContainerInterface
    {
        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($this->site());
    }

    public function remove(): void
    {
        $this->project->remove();
    }
}
