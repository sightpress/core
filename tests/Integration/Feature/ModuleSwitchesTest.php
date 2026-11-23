<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Feature;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Migration\MigrationConfigurationFactory;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\Modules\CalendarModule;
use SightPress\Core\Tests\Support\Modules\EventsModule;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;

/**
 * Per-site module switches on the site's database engine (F-2, ADR-0034): the `site_module` choices, a module's
 * migrations run when it is turned on and undone when its data is deleted, and its routes follow — on SQLite here and
 * on MySQL, MariaDB and PostgreSQL in the database matrix.
 */
#[CoversNothing]
final class ModuleSwitchesTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $this->project->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 2,
            'sites' => ['demo' => SiteFixture::entry('demo', [
                'domains' => ['example.org'],
                'db_dsn' => SiteFixture::secretBox()->encrypt(TestDatabases::dsn('switches', $this->project->path())),
            ])],
        ], true) . ';');
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            [...CoreModules::candidates(), new ModuleCandidate(new EventsModule(), 'acme/events', '*'), new ModuleCandidate(new CalendarModule(), 'acme/events', '*')],
        );

        // A site made before F-2: migrated with core's modules only, so it has no site_module table and no choices.
        $dependencies = DependencyFactory::fromConnection(
            new ExistingConfiguration(new MigrationConfigurationFactory()->create($this->kernel->modules()->only(array_map(
                static fn (ModuleCandidate $candidate): string => $candidate->module->manifest()->id,
                CoreModules::candidates(),
            )))),
            new ExistingConnection($this->connection()),
        );
        $dependencies->getMetadataStorage()->ensureInitialized();
        $dependencies->getMigrator()->migrate(
            $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion($dependencies->getVersionAliasResolver()->resolveVersionAlias('latest')),
            new MigratorConfiguration(),
        );
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testSwitchesAModuleOffAndOnAndDeletesItsData(): void
    {
        $modules = Services::get($this->kernel->container(), SiteModules::class);
        self::assertTrue(Services::get($this->services(), ModuleRegistry::class)->has(EventsModule::ID), 'Events is on by default');
        self::assertFalse($this->table('acme_event'), 'its migrations haven\'t run yet');

        self::assertFalse($modules->turnOn($this->site(), EventsModule::ID, null), 'it is already on');
        self::assertTrue($modules->turnOn($this->site(), CalendarModule::ID, 7));
        self::assertTrue($this->table('acme_event'), 'turning on a module runs the pending migrations of the modules that are on');
        self::assertSame(200, $this->get('/calendar'));
        $this->connection()->insert('acme_event', ['title' => 'Book fair']);

        self::assertTrue($modules->turnOff($this->site(), CalendarModule::ID, 7));
        self::assertTrue($modules->turnOff($this->site(), EventsModule::ID, 7));
        self::assertSame([404, 404], [$this->get('/events'), $this->get('/calendar')]);
        self::assertSame(['Book fair'], $this->connection()->createQueryBuilder()->select('title')->from('acme_event')->fetchFirstColumn());
        self::assertSame(
            [[CalendarModule::ID, false, 7], [EventsModule::ID, false, 7]],
            array_map(
                static fn (array $row): array => [$row['module_id'], (bool) $row['enabled'], is_numeric($row['changed_by']) ? (int) $row['changed_by'] : null],
                $this->connection()->createQueryBuilder()->select('module_id', 'enabled', 'changed_by')->from('site_module')->orderBy('module_id')->fetchAllAssociative(),
            ),
        );

        self::assertSame(1, $modules->deleteData($this->site(), EventsModule::ID, 7));
        self::assertFalse($this->table('acme_event'));
        self::assertTrue($modules->turnOn($this->site(), EventsModule::ID, 7));
        self::assertTrue($this->table('acme_event'));
        self::assertSame([], $this->connection()->createQueryBuilder()->select('title')->from('acme_event')->fetchFirstColumn());
        self::assertSame(200, $this->get('/events'));
    }

    private function table(string $name): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist([$name]);
    }

    private function get(string $path): int
    {
        return new Browser($this->kernel, 'http://example.org')->request('GET', $path)->getStatusCode();
    }

    private function site(): Site
    {
        return Services::get($this->kernel->container(), SiteRepository::class)->find('demo') ?? throw new \LogicException('No site');
    }

    private function services(): \Psr\Container\ContainerInterface
    {
        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($this->site());
    }

    private function connection(): Connection
    {
        return Services::get($this->services(), Connection::class);
    }
}
