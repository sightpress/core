<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Feature;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Feature\Command\DisableSiteModuleCommand;
use SightPress\Core\Feature\Command\EnableSiteModuleCommand;
use SightPress\Core\Feature\Command\ListSiteModulesCommand;
use SightPress\Core\Feature\Command\SiteModuleCommand;
use SightPress\Core\Feature\FeatureMigrations;
use SightPress\Core\Feature\FeatureModule;
use SightPress\Core\Feature\Http\DeleteFeatureDataHandler;
use SightPress\Core\Feature\Http\FeaturesHandler;
use SightPress\Core\Feature\Http\TurnOffFeatureHandler;
use SightPress\Core\Http\ModuleGate;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Settings\SettingCatalogue;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Module\ModuleAbout;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModulePolicy;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Module\SiteModuleStore;
use SightPress\Core\Navigation\AdminNavigation;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Rbac\SeedOnModuleEnabled;
use SightPress\Core\Site\Command\CreateSiteCommand;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Module\Event\SiteModuleChanged;
use SightPress\Core\Tenant\SiteModuleException;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteModuleState;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\Modules\CalendarModule;
use SightPress\Core\Tests\Support\Modules\EventsModule;
use SightPress\Core\Tests\Support\Modules\ExampleEvent;
use SightPress\Core\Tests\Support\Modules\RecordingListener;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Console\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Per-site module switches through the whole kernel (F-2): two optional modules of a Composer package, "Events"
 * (on by default, with a table) and "acme.calendar" (off by default, needs Events), switched on the *Features*
 * screen and with `site:module:*`.
 */
#[CoversClass(SiteModules::class)]
#[CoversClass(SiteModuleState::class)]
#[CoversClass(SiteModuleException::class)]
#[CoversClass(SiteModuleStore::class)]
#[CoversClass(SiteMigrator::class)]
#[CoversClass(SiteContainerFactory::class)]
#[CoversClass(ModulePolicy::class)]
#[CoversClass(ModuleGate::class)]
#[CoversClass(FeatureModule::class)]
#[CoversClass(FeatureMigrations::class)]
#[CoversClass(FeaturesHandler::class)]
#[CoversClass(TurnOffFeatureHandler::class)]
#[CoversClass(DeleteFeatureDataHandler::class)]
#[CoversClass(SiteModuleCommand::class)]
#[CoversClass(ListSiteModulesCommand::class)]
#[CoversClass(EnableSiteModuleCommand::class)]
#[CoversClass(DisableSiteModuleCommand::class)]
#[CoversClass(CreateSiteCommand::class)]
#[CoversClass(SeedOnModuleEnabled::class)]
#[CoversClass(\SightPress\Core\Http\RouteInstaller::class)]
#[UsesNamespace('SightPress\Core')]
final class FeaturesTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private TestProject $project;

    private Kernel $kernel;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        RecordingListener::$log = [];
        $this->boot();
        self::assertSame(Command::SUCCESS, $this->create('demo'), $this->stderr);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsTheOptionalModulesOfASite(): void
    {
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'demo']));
        self::assertSame("acme.events (Events): on\nacme.calendar (acme.calendar): off\n", $this->stdout);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:module:list', 'slug' => 'nope']));
        self::assertStringContainsString('There is no site "nope".', $this->stderr);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => 'page']));
        self::assertStringContainsString('There is no optional feature “page”.', $this->stderr);
    }

    public function testAModuleThatIsOffRegistersNothingAndKeepsItsData(): void
    {
        $this->connection()->insert('acme_event', ['title' => 'Book fair']);
        self::assertSame(200, $this->get('/events'));
        self::assertTrue($this->registry()->has(EventsModule::ID));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID]), $this->stderr);
        self::assertSame("Events (acme.events) is off for \"demo\". Its data is kept.\n", $this->stdout);
        self::assertSame(404, $this->get('/events'));
        self::assertSame(404, $this->get('/admin/events'));
        self::assertFalse($this->registry()->has(EventsModule::ID));
        self::assertNull(Services::get($this->services(), SettingCatalogue::class)->get('acme.events.title'));
        self::assertSame([], array_filter(
            Services::get($this->services(), AdminNavigation::class)->for(['admin.access'], '/admin'),
            static fn ($entry): bool => $entry->label === 'Events',
        ));
        Services::get($this->services(), EventDispatcherInterface::class)->dispatch(new ExampleEvent());
        self::assertSame([], RecordingListener::$log, 'its listeners don\'t run');
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID]));
        self::assertSame("Events (acme.events) is already off for \"demo\".\n", $this->stdout);

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => EventsModule::ID]), $this->stderr);
        self::assertSame("Events (acme.events) is on for \"demo\".\n", $this->stdout);
        self::assertSame(200, $this->get('/events'));
        self::assertSame(['Book fair'], $this->connection()->createQueryBuilder()->select('title')->from('acme_event')->fetchFirstColumn());
        Services::get($this->services(), EventDispatcherInterface::class)->dispatch(new ExampleEvent());
        self::assertSame(['events:' . ExampleEvent::class], RecordingListener::$log);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => EventsModule::ID]));
        self::assertSame("Events (acme.events) is already on for \"demo\".\n", $this->stdout);

        self::assertSame(['Turned on Events.', 'Turned off Events.'], $this->audited());
        self::assertStringContainsString('Site "demo" module "acme.events" turned off.', $this->project->log());
    }

    public function testAModuleNeedsTheModulesItRequiresAndTheReverse(): void
    {
        $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID]);

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => CalendarModule::ID]));
        self::assertStringContainsString('acme.calendar needs Events, which is off. Turn on Events first.', $this->stderr);

        $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => EventsModule::ID]);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => CalendarModule::ID]), $this->stderr);
        self::assertSame(200, $this->get('/calendar'));

        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID]));
        self::assertStringContainsString('acme.calendar needs Events, so Events can’t be turned off while acme.calendar is on. Turn off acme.calendar first.', $this->stderr);
        $events = $this->modules()->get($this->site(), EventsModule::ID);
        self::assertSame([true, ['acme.calendar']], [$events->on, $events->neededBy]);
        self::assertSame(['Events'], $this->modules()->get($this->site(), CalendarModule::ID)->requires);
    }

    public function testPurgingDeletesTheModulesDataForGood(): void
    {
        $this->connection()->insert('acme_event', ['title' => 'Book fair']);
        self::assertTrue($this->modules()->hasData($this->site(), EventsModule::ID));
        try {
            $this->modules()->deleteData($this->site(), EventsModule::ID, null);
            self::fail('a module that is on keeps its data');
        } catch (SiteModuleException $exception) {
            self::assertSame('Turn off Events before you delete its data.', $exception->getMessage());
        }

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID, '--purge-data' => true]), $this->stderr);
        self::assertSame("Events (acme.events) is off for \"demo\".\nDeleted the data of Events: undid 1 database migration.\n", $this->stdout);
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist(['acme_event']));
        self::assertFalse($this->modules()->hasData($this->site(), EventsModule::ID));

        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID, '--purge-data' => true]));
        self::assertSame("Events (acme.events) is already off for \"demo\".\nEvents has no data to delete.\n", $this->stdout);
        self::assertSame(0, $this->modules()->deleteData($this->site(), CalendarModule::ID, null), 'a module without tables has nothing to delete');

        $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => EventsModule::ID]);
        self::assertSame([], $this->connection()->createQueryBuilder()->select('title')->from('acme_event')->fetchFirstColumn(), 'it starts afresh');
        self::assertSame(['Turned on Events.', 'Deleted the data of Events.', 'Turned off Events.'], $this->audited());
    }

    public function testTheTenantCanLockAModuleForEverySite(): void
    {
        $this->boot(['locked' => [EventsModule::ID => true]]);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'demo']));
        self::assertStringContainsString('acme.events (Events): on, set by the tenant (config/tenant.php)', $this->stdout);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:module:disable', 'slug' => 'demo', 'module' => EventsModule::ID]));
        self::assertStringContainsString('Events is set by your organisation, so it can’t be changed for this site.', $this->stderr);
        self::assertSame(Command::INVALID, $this->create('other', '--without', EventsModule::ID));
        self::assertStringContainsString('“acme.events” is set by your organisation to be on for every site.', $this->stderr);

        $this->boot(['locked' => [EventsModule::ID => false]]);
        self::assertSame(404, $this->get('/events'));
        self::assertFalse($this->kernel->modules()->has(EventsModule::ID), 'a module locked off isn\'t loaded at all');
        self::assertFalse($this->kernel->modules()->has(CalendarModule::ID));
        self::assertSame(Command::FAILURE, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => EventsModule::ID]));
        self::assertStringContainsString('set by your organisation', $this->stderr);
    }

    public function testANewSiteCanStartWithoutAModuleAndFollowsTheTenantsDefaults(): void
    {
        self::assertSame(Command::INVALID, $this->create('other', '--without', 'nope'));
        self::assertStringContainsString('“nope” is not an optional module. Choose from: acme.events, acme.calendar.', $this->stderr);

        self::assertSame(Command::SUCCESS, $this->create('other', '--without', ' acme.events , ACME.events'), $this->stderr);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'other']));
        self::assertSame("acme.events (Events): off\nacme.calendar (acme.calendar): off\n", $this->stdout);
        self::assertFalse($this->connection('other')->createSchemaManager()->tablesExist(['acme_event']), 'its migrations didn\'t run');

        $this->boot(['defaults' => [EventsModule::ID => false]]);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'demo']));
        self::assertSame("acme.events (Events): on\nacme.calendar (acme.calendar): off\n", $this->stdout, 'a site keeps the choice it was created with');
        self::assertSame(Command::SUCCESS, $this->create('third'), $this->stderr);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'third']));
        self::assertSame("acme.events (Events): off\nacme.calendar (acme.calendar): off\n", $this->stdout);
    }

    public function testUnavailableAndRemovedModulesAreReportedAndTheSiteKeepsWorking(): void
    {
        $this->connection()->insert('acme_event', ['title' => 'Book fair']);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:enable', 'slug' => 'demo', 'module' => CalendarModule::ID]));
        $this->console(['command' => 'system:check']);
        self::assertStringNotContainsString('Module ', $this->stderr, 'nothing to report about modules yet');

        // The calendar's package is removed, and a module that needs a package that isn't installed is added.
        $this->boot(['locked' => [CalendarModule::ID => true]], [
            new ModuleCandidate(new EventsModule(), 'acme/events', '*'),
            new ModuleCandidate(new \SightPress\Core\Tests\Support\FakeModule('acme.old', ['acme.tickets' => '^1.0']), 'acme/old', '*'),
        ]);
        self::assertSame([200, 404], [$this->get('/events'), $this->get('/calendar')]);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'demo']));
        self::assertStringContainsString("acme.events (acme.events): on\nacme.old (acme/old): unavailable, so it is off for every site. It needs module \"acme.tickets\", which is not installed or is unavailable.\n", $this->stdout);
        self::assertStringContainsString('acme.calendar: turned on for this site but no longer installed. Its data is kept; install its package again to bring it back.', $this->stdout);
        self::assertSame(Command::FAILURE, $this->console(['command' => 'system:check']));
        self::assertStringContainsString('Module acme.old (acme/old) is unavailable, so no site uses it:', $this->stderr);
        self::assertStringContainsString('Tenant setting "modules.locked" in config/tenant.php names module "acme.calendar", which is not installed or is unavailable', $this->stderr);

        $screen = (string) $this->signedIn('ada@example.org')->request('GET', '/admin/features')->getBody();
        self::assertStringContainsString('<h2 id="features-missing">Features that were removed</h2>', $screen);
        self::assertStringContainsString('<li>acme.calendar</li>', $screen);
        self::assertStringContainsString('<h2 id="features-unavailable">Features that can’t be used</h2>', $screen);
        self::assertStringContainsString('<dt>acme.old (acme/old)</dt>', $screen);

        // Reinstalling the package brings it back, on, with its data.
        $this->boot();
        self::assertSame([200, 200], [$this->get('/events'), $this->get('/calendar')]);
        self::assertSame(['Book fair'], $this->connection()->createQueryBuilder()->select('title')->from('acme_event')->fetchFirstColumn());
        $screen = (string) $this->signedIn('ada@example.org')->request('GET', '/admin/features')->getBody();
        self::assertStringNotContainsString('features-missing', $screen);
        self::assertStringNotContainsString('features-unavailable', $screen);
    }

    public function testWithoutOptionalModulesThereIsNothingToSwitch(): void
    {
        $this->project->remove();
        $this->project = new TestProject();
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            self::coreModules(),
        );
        self::assertSame(Command::INVALID, $this->create('demo', '--without', 'analytics'));
        self::assertStringContainsString('“analytics” is not an optional module. This install has none: every module is always on.', $this->stderr);
        self::assertSame(Command::SUCCESS, $this->create('demo'), $this->stderr);
        self::assertSame(Command::SUCCESS, $this->console(['command' => 'site:module:list', 'slug' => 'demo']));
        self::assertSame("The site \"demo\" has no optional modules: every installed module is always on.\n", $this->stdout);
        self::assertSame(200, $this->signedIn('ada@example.org')->request('GET', '/admin/features')->getStatusCode());
    }

    public function testAdminsSwitchFeaturesOnTheFeaturesScreen(): void
    {
        $this->connection()->insert('acme_event', ['title' => 'Book fair']);
        $browser = $this->signedIn('ada@example.org');

        $screen = (string) $browser->request('GET', '/admin/features')->getBody();
        self::assertStringContainsString('<title>Features - Admin - demo</title>', $screen);
        self::assertStringContainsString('<a href="/admin/features" aria-current="page">Features</a>', $screen);
        self::assertStringContainsString('<h2 id="feature-acme-events" tabindex="-1">Events</h2>', $screen);
        self::assertStringContainsString('<p>A calendar of events, with a page for each one.</p>', $screen);
        self::assertStringContainsString('<strong>When it is off:</strong> The events pages answer “not found”', $screen);
        self::assertStringContainsString('>Turn off Events</button>', $screen);
        self::assertStringContainsString('<p>Needs: Events.</p>', $screen);
        self::assertStringContainsString('name="on" value="acme.calendar" class="button">Turn on acme.calendar</button>', $screen);

        $question = $browser->submit('/admin/features', ['module' => EventsModule::ID], '/admin/features/turn-off');
        self::assertSame(200, $question->getStatusCode());
        self::assertStringContainsString('<h1>Turn off Events?</h1>', (string) $question->getBody());
        self::assertSame(200, $this->get('/events'), 'nothing changes before it is confirmed');

        $off = $browser->submit('/admin/features/turn-off?module=acme.events', ['module' => EventsModule::ID, 'confirm' => '1']);
        self::assertSame([303, '/admin/features#feature-acme-events'], [$off->getStatusCode(), $off->getHeaderLine('Location')]);
        $screen = (string) $browser->request('GET', '/admin/features')->getBody();
        self::assertStringContainsString('Events is off. Its data is kept: turn it on again to bring it back.', $screen);
        self::assertStringContainsString('href="/admin/features/delete-data?module=acme.events">Delete the data of Events</a>', $screen);
        self::assertStringNotContainsString('>Events</a>', $screen, 'its admin menu entry is gone');
        self::assertSame(404, $this->get('/events'));
        self::assertSame('/admin/features#feature-acme-events', $browser->submit('/admin/features', ['module' => EventsModule::ID, 'confirm' => '1'], '/admin/features/turn-off')->getHeaderLine('Location'));
        self::assertStringContainsString('Events was already off.', (string) $browser->request('GET', '/admin/features')->getBody());

        $delete = (string) $browser->request('GET', '/admin/features/delete-data?module=acme.events')->getBody();
        self::assertStringContainsString('<h1>Delete the data of Events?</h1>', $delete);
        self::assertSame('/admin/features#feature-acme-events', $browser->submit('/admin/features/delete-data?module=acme.events', ['module' => EventsModule::ID])->getHeaderLine('Location'));
        self::assertStringContainsString('Deleted the data of Events. Turning it on again starts afresh.', (string) $browser->request('GET', '/admin/features')->getBody());
        $browser->submit('/admin/features', ['module' => EventsModule::ID], '/admin/features/delete-data');
        self::assertStringContainsString('Events has no data to delete.', (string) $browser->request('GET', '/admin/features')->getBody());

        $on = $browser->submit('/admin/features', ['on' => EventsModule::ID]);
        self::assertSame('/admin/features#feature-acme-events', $on->getHeaderLine('Location'));
        self::assertStringContainsString('Events is on.', (string) $browser->request('GET', '/admin/features')->getBody());
        self::assertSame(200, $this->get('/events'));
        $browser->submit('/admin/features', ['on' => EventsModule::ID]);
        self::assertStringContainsString('Events was already on.', (string) $browser->request('GET', '/admin/features')->getBody());

        $browser->submit('/admin/features', ['on' => CalendarModule::ID]);
        $screen = (string) $browser->request('GET', '/admin/features')->getBody();
        self::assertStringContainsString('acme.calendar needs it: turn that off first to turn this off.', $screen);
        $browser->submit('/admin/features', ['module' => EventsModule::ID, 'confirm' => '1'], '/admin/features/turn-off');
        self::assertStringContainsString('acme.calendar needs Events, so Events can’t be turned off while acme.calendar is on.', (string) $browser->request('GET', '/admin/features')->getBody());
        self::assertStringContainsString('Turn off Events before you delete its data.', $this->flash($browser, '/admin/features/delete-data?module=acme.events', ['module' => EventsModule::ID]));

        self::assertSame('/admin/features', $browser->submit('/admin/features', ['on' => ''])->getHeaderLine('Location'));
        self::assertStringContainsString('There is no optional feature “”.', (string) $browser->request('GET', '/admin/features')->getBody());
        self::assertSame(404, $browser->request('GET', '/admin/features/turn-off?module=page')->getStatusCode());
        self::assertSame(404, $browser->request('GET', '/admin/features/delete-data?module=nope')->getStatusCode());
        self::assertSame(['Turned on acme.calendar.', 'Turned on Events.', 'Deleted the data of Events.', 'Turned off Events.'], $this->audited());
    }

    public function testFeaturesTheOrganisationSetHaveNoButton(): void
    {
        $this->boot(['locked' => [EventsModule::ID => true]]);
        $browser = $this->signedIn('ada@example.org');

        $screen = (string) $browser->request('GET', '/admin/features')->getBody();
        self::assertStringContainsString('<strong>Status:</strong>' . "\n        " . 'On, set by your organisation', $screen);
        self::assertStringContainsString('Your organisation chose this for every site, so it can’t be changed here.', $screen);
        self::assertStringNotContainsString('Turn off Events', $screen);
        self::assertStringContainsString('Events is set by your organisation', $this->flash($browser, '/admin/features/turn-off?module=acme.events', ['module' => EventsModule::ID]));

        $this->boot(['locked' => [CalendarModule::ID => false]]);
        $screen = (string) $this->signedIn('ada@example.org')->request('GET', '/admin/features')->getBody();
        self::assertStringContainsString('Off, set by your organisation', $screen);
    }

    public function testOnlyPeopleWhoManageFeaturesMayUseTheScreen(): void
    {
        $browser = $this->signedIn('bea@example.org', 'editor');

        self::assertSame(403, $browser->request('GET', '/admin/features')->getStatusCode());
        self::assertSame(403, $browser->request('GET', '/admin/features/turn-off?module=acme.events')->getStatusCode());
        self::assertSame(403, $browser->request('GET', '/admin/features/delete-data?module=acme.events')->getStatusCode());
        $roles = Services::get($this->services(), RoleRepository::class);
        self::assertSame(['admin'], array_values(array_map(static fn ($role): string => $role->name(), array_filter(
            $roles->roles(),
            static fn ($role): bool => in_array(FeaturesHandler::PERMISSION, $role->permissionNames(), true),
        ))));
    }

    /**
     * @param array<string, array<string, bool>> $modules  `modules` of the tenant config
     * @param list<ModuleCandidate>|null         $packages the external modules installed; null: Events and Calendar
     */
    private function boot(array $modules = [], ?array $packages = null): void
    {
        if (!isset($this->project)) {
            $this->project = new TestProject(['modules' => $modules]);
        } else {
            $this->project->directory->write('config/tenant.php', '<?php return ' . var_export([
                'log' => ['path' => $this->project->path() . '/var/log/test.log'],
                'modules' => $modules,
            ], true) . ';');
        }
        $about = [EventsModule::ID => ModuleAbout::fromArray(EventsModule::ABOUT, EventsModule::ID)];
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            [
                ...self::coreModules(),
                ...$packages ?? [
                    new ModuleCandidate(new EventsModule(), 'acme/events', '*', $about),
                    new ModuleCandidate(new CalendarModule(), 'acme/events', '*', $about),
                ],
            ],
        );
    }

    /**
     * Core's modules without its own optional ones (site search), so these tests only see the test modules.
     *
     * @return list<ModuleCandidate>
     */
    private static function coreModules(): array
    {
        return array_values(array_filter(CoreModules::candidates(), static fn (ModuleCandidate $candidate): bool => $candidate->module->manifest()->required));
    }

    private function create(string $slug, string ...$options): int
    {
        $input = [
            'command' => 'site:create',
            'slug' => $slug,
            '--domain' => $slug === 'demo' ? 'example.org' : $slug . '.example',
            '--db' => 'sqlite:///' . $this->project->path() . '/sites/' . $slug . '.sqlite',
            '--owner-type' => 'organization',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ];
        for ($i = 0; $i + 1 < count($options); $i += 2) {
            $input[$options[$i]] = $options[$i + 1];
        }

        return $this->console($input);
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

    private function get(string $path): int
    {
        return new Browser($this->kernel, 'http://example.org')->request('GET', $path)->getStatusCode();
    }

    private function signedIn(string $email, string $role = 'admin'): Browser
    {
        $container = $this->services();
        $users = Services::get($container, UserRepository::class);
        $user = $users->findByEmail($email) ?? new User($email, 'Bea', 'Okafor', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));

        $browser = new Browser($this->kernel, 'http://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }

    /**
     * Posts a form and returns the screen it comes back to, with its flash message.
     *
     * @param array<string, string> $fields
     */
    private function flash(Browser $browser, string $path, array $fields): string
    {
        $browser->submit('/admin/features', $fields, $path);

        return (string) $browser->request('GET', '/admin/features')->getBody();
    }

    /**
     * @return list<string> the summaries of the feature changes in the audit log, newest first
     */
    private function audited(): array
    {
        $entries = Services::get($this->services(), AuditLog::class)->find(new AuditFilter(), 50);

        return array_values(array_map(
            static fn ($entry): string => $entry->summary,
            array_filter($entries, static fn ($entry): bool => $entry->entityType === 'feature'),
        ));
    }

    private function site(string $slug = 'demo'): Site
    {
        return Services::get($this->kernel->container(), SiteRepository::class)->find($slug) ?? throw new \LogicException('No site');
    }

    private function services(string $slug = 'demo'): ContainerInterface
    {
        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($this->site($slug));
    }

    private function connection(string $slug = 'demo'): Connection
    {
        return Services::get($this->services($slug), Connection::class);
    }

    private function registry(): ModuleRegistry
    {
        return Services::get($this->services(), ModuleRegistry::class);
    }

    private function modules(): SiteModules
    {
        return Services::get($this->kernel->container(), SiteModules::class);
    }
}
