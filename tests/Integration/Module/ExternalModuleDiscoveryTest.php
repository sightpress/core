<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Module;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\Modules\BrokenModule;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * X-1 acceptance: the `acme/events` fixture package (`tests/Fixtures/modules/acme-events`, written only against
 * module-interface) installed the way Composer installs a `path` repository, found by discovery next to three broken
 * packages. It is on for site A and off for site B: B answers 404 on its routes and has no menu item, block type or
 * SEO entries; turning it on for B runs its migrations; its data survives off → on; the broken packages are listed
 * as unavailable and every site keeps serving pages (ADR-0065).
 */
#[CoversNothing]
final class ExternalModuleDiscoveryTest extends TestCase
{
    private const string MODULE = 'acme.events';

    private TestProject $project;

    private Kernel $kernel;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        BrokenModule::$failAt = 'register';
        $this->project = new TestProject();
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/modules/acme-events/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        $contract = ['sightpress/module-interface' => '^0.5'];
        $this->project->directory->write('vendor/composer/installed.json', json_encode(['packages' => [
            // As Composer writes a path repository's package: its composer.json, with where it was installed from.
            $fixture + ['version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => '../modules/acme-events'], 'install-path' => '../acme/events'],
            ['name' => 'acme/bad-extra', 'require' => $contract, 'extra' => ['sightpress' => ['module' => ['not' => 'a list']]]],
            ['name' => 'acme/missing-class', 'require' => $contract, 'extra' => ['sightpress' => ['module' => 'Acme\Missing\Module']]],
            ['name' => 'acme/broken', 'require' => $contract, 'extra' => ['sightpress' => ['module' => BrokenModule::class]]],
        ]], JSON_THROW_ON_ERROR));
        $this->boot('test');
    }

    protected function tearDown(): void
    {
        BrokenModule::$failAt = 'create';
        $this->project->remove();
    }

    public function testAnExternalPackageIsDiscoveredAndSwitchedPerSite(): void
    {
        self::assertTrue($this->kernel->modules()->has(self::MODULE), 'discovered from installed.json');
        self::assertSame(
            ['acme/bad-extra', 'acme/missing-class', 'acme.broken (acme/broken)'],
            array_map(static fn ($module): string => $module->identity(), Services::get($this->kernel->container(), SiteModules::class)->unavailable()),
        );

        self::assertSame(Command::SUCCESS, $this->create('a', TestDatabases::dsn('discovery', $this->project->path())), $this->stderr);
        // A second tenant database on the server would replace the first one (DB matrix), so site B uses SQLite.
        self::assertSame(Command::SUCCESS, $this->create('b', 'sqlite:///' . $this->project->path() . '/sites/b.sqlite', self::MODULE), $this->stderr);
        self::assertTrue($this->table('a'), 'its migrations ran for A, where it is on');
        self::assertFalse($this->table('b'), 'and not for B, which started without it');
        $this->connection('a')->insert('acme_events_event', ['slug' => 'open-day', 'title' => 'Open day', 'starts_at' => '2026-12-05T10:00:00Z']);

        self::assertSame([200, 404], [$this->get('a', '/events/open-day'), $this->get('b', '/events/open-day')]);
        self::assertSame([200, 200], [$this->get('a', '/'), $this->get('b', '/')], 'the broken packages stop no site');
        self::assertSame([true, false], [$this->hasMenuItem('a'), $this->hasMenuItem('b')]);
        self::assertSame([true, false], [$this->hasBlock('a'), $this->hasBlock('b')]);

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'b']));
        self::assertStringContainsString('acme.events (acme.events): off', $this->stdout);
        self::assertStringContainsString('acme/missing-class: unavailable, so it is off for every site. The module class Acme\Missing\Module does not exist.', $this->stdout);

        // SEO entries are only made in production.
        $this->boot('production');
        self::assertStringContainsString('/events/open-day</loc>', $this->body('a', '/sitemap.xml'));
        self::assertStringContainsString('/events/open-day.md', $this->body('a', '/llms.txt'));
        self::assertStringNotContainsString('/events/', $this->body('b', '/sitemap.xml'));
        self::assertStringNotContainsString('/events/', $this->body('b', '/llms.txt'));

        // On for B: its migrations run and everything comes back; off again: its data stays.
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:enable', 'slug' => 'b', 'module' => self::MODULE]), $this->stderr);
        self::assertTrue($this->table('b'));
        $this->connection('b')->insert('acme_events_event', ['slug' => 'fair', 'title' => 'Book fair', 'starts_at' => '2026-12-06T10:00:00Z']);
        self::assertSame(200, $this->get('b', '/events/fair'));
        self::assertTrue($this->hasBlock('b'));
        self::assertStringContainsString('/events/fair</loc>', $this->body('b', '/sitemap.xml'), 'turning a module on refreshes the sitemap');
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:disable', 'slug' => 'b', 'module' => self::MODULE]), $this->stderr);
        self::assertSame(404, $this->get('b', '/events/fair'));
        self::assertStringNotContainsString('/events/', $this->body('b', '/sitemap.xml'));
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:enable', 'slug' => 'b', 'module' => self::MODULE]), $this->stderr);
        self::assertSame(200, $this->get('b', '/events/fair'), 'its data survived');
        self::assertSame(200, $this->get('a', '/events/open-day'));
    }

    private function boot(string $environment): void
    {
        $this->kernel = Kernel::fromConfig(new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment, 'APP_SECRET' => SiteFixture::SECRET]));
    }

    private function create(string $slug, string $dsn, ?string $without = null): int
    {
        return $this->console([
            'command' => 'site:create',
            'slug' => $slug,
            '--domain' => $slug . '.example',
            '--db' => $dsn,
            '--owner-type' => 'organization',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ] + ($without === null ? [] : ['--without' => $without]));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = $this->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }

    private function get(string $slug, string $path): int
    {
        return new Browser($this->kernel, 'https://' . $slug . '.example')->request('GET', $path)->getStatusCode();
    }

    private function body(string $slug, string $path): string
    {
        return (string) new Browser($this->kernel, 'https://' . $slug . '.example')->request('GET', $path)->getBody();
    }

    private function hasMenuItem(string $slug): bool
    {
        $entries = Services::get($this->services($slug), AdminNavigation::class)->for(['admin.access', 'events.manage'], '/admin');

        return array_filter($entries, static fn ($entry): bool => $entry->label === 'Events') !== [];
    }

    private function hasBlock(string $slug): bool
    {
        return Services::get($this->services($slug), BlockTypeCatalogue::class)->get('acme_events.event_card') !== null;
    }

    private function table(string $slug): bool
    {
        return $this->connection($slug)->createSchemaManager()->tablesExist(['acme_events_event']);
    }

    private function connection(string $slug): Connection
    {
        return Services::get($this->services($slug), Connection::class);
    }

    private function services(string $slug): \Psr\Container\ContainerInterface
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find($slug);
        self::assertInstanceOf(Site::class, $site);

        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($site);
    }
}
