<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Module;

use Acme\Events\EventsModule;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Content\BlockIconCatalogue;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Infrastructure\Schedule\ModuleJobs;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Module\ModuleRegistry;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;
use SightPress\Module\Event\PageTransitioned;
use SplFileInfo;

/**
 * X-2 acceptance (ADR-0057): the "events" feature of the module tutorial, written only against module-interface 0.5
 * (`tests/Fixtures/modules/acme-events`), works in core: a permission-checked admin screen in the admin shell with a
 * translated title, settings, the audit log and a notice; a public page in the theme with `Event` JSON-LD and a
 * Markdown version; SEO, search, a scheduled job, translations and a listener on a core event.
 */
#[CoversNothing]
final class ExternalModuleContractTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('external', locales: ['en-GB', 'pt-PT'], extraModules: [new ModuleCandidate(new EventsModule(), 'acme/events', '*')]);
        $now = new DateTimeImmutable();
        foreach (['ana@example.org' => 'admin', 'cleo@example.org' => 'contributor'] as $email => $role) {
            $user = new User($email, ucfirst(explode('@', $email)[0]), 'Tester', 'Europe/London', $now);
            $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
            Services::get($this->site->container(), UserRepository::class)->save($user);
            $roles = Services::get($this->site->container(), RoleRepository::class);
            $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
        }
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testTheFixtureModuleImportsNothingFromCore(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/Fixtures/modules/acme-events/src'));
        $checked = 0;
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                self::assertStringNotContainsString('SightPress\Core', (string) file_get_contents($file->getPathname()), $file->getFilename());
                ++$checked;
            }
        }
        self::assertGreaterThan(8, $checked);
    }

    public function testRoutePermissionsAreCheckedBeforeTheModuleRuns(): void
    {
        $visitor = new Browser($this->site->kernel, 'https://example.org');
        $redirect = $visitor->request('GET', '/admin/events?page=2');
        self::assertSame(303, $redirect->getStatusCode());
        self::assertSame('/login?return=%2Fadmin%2Fevents%3Fpage%3D2', $redirect->getHeaderLine('Location'));

        self::assertSame(403, $this->signedIn('cleo@example.org')->request('GET', '/admin/events')->getStatusCode(), 'contributors don\'t have events.manage');
    }

    public function testAnAdminScreenWithSettingsAuditAndANotice(): void
    {
        $ana = $this->signedIn('ana@example.org');
        $page = (string) $ana->request('GET', '/admin/events')->getBody();
        self::assertStringContainsString('<title>Events - Admin', $page);
        self::assertStringContainsString('<h1>Events</h1>', $page);
        self::assertStringContainsString('Showing up to 20 events per page.', $page);
        self::assertMatchesRegularExpression('#<a href="/admin/events"[^>]*>\s*Events\s*</a>#', $page, 'the menu item');

        $refused = $ana->submit('/admin/events', ['title' => '', 'starts_at' => 'soon']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('Enter the event’s name.', (string) $refused->getBody());

        $added = $ana->submit('/admin/events', ['title' => 'Open day', 'starts_at' => '2026-12-01T10:00']);
        self::assertSame(303, $added->getStatusCode());
        self::assertSame('/admin/events', $added->getHeaderLine('Location'));
        $after = (string) $ana->request('GET', '/admin/events')->getBody();
        self::assertStringContainsString('Added “Open day”.', $after);
        self::assertStringContainsString('<a href="/events/open-day">Open day</a>', $after);

        $audit = $this->connection()->createQueryBuilder()->select('event_action', 'entity_type', 'entity_id', 'summary')->from('audit_log')->where("event_action LIKE 'acme.%'")->fetchAllAssociative();
        self::assertSame([['event_action' => 'acme.events.event_added', 'entity_type' => 'event', 'entity_id' => 'open-day', 'summary' => 'Added the event “Open day”.']], $audit);
    }

    public function testAPublicPageInTheThemeWithStructuredDataAndMarkdown(): void
    {
        $this->connection()->insert('acme_events_event', ['slug' => 'open-day', 'title' => 'Open day', 'starts_at' => '2026-12-01T10:00']);
        $visitor = new Browser($this->site->kernel, 'https://example.org');

        $response = $visitor->request('GET', '/events/open-day');
        $page = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<h1>Open day</h1>', $page);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/events/open-day">', $page);
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://example.org/events/open-day.md">', $page);
        self::assertStringContainsString('"@type":"Event","name":"Open day","startDate":"2026-12-01T10:00"', $page);
        self::assertStringContainsString('<meta name="description" content="An event at the venue.">', $page);
        self::assertStringContainsString('<main', $page, 'inside the theme\'s shell');

        $markdown = $visitor->request('GET', '/events/open-day.md');
        self::assertSame("# Open day\n\nStarts at 2026-12-01T10:00.\n", (string) $markdown->getBody());
        self::assertStringStartsWith('text/markdown', $markdown->getHeaderLine('Content-Type'));

        $missing = $visitor->request('GET', '/events/nothing');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('noindex', $missing->getHeaderLine('X-Robots-Tag'));
    }

    public function testRegistrationsScheduledJobsTranslationsAndCoreEvents(): void
    {
        $this->connection()->insert('acme_events_event', ['slug' => 'open-day', 'title' => 'Open day', 'starts_at' => '2026-12-01T10:00']);
        $services = $this->site->container();
        $registry = Services::get($services, ModuleRegistry::class);
        self::assertSame(['Acme\Events\SeoEntries'], array_map(static fn ($r): string => $r->item, $registry->seoProviders()));
        self::assertContains('Acme\Events\SearchDocuments', array_map(static fn ($r): string => $r->item, $registry->searchProviders()));
        self::assertContains('Acme\Events\EventCardBlock', array_map(static fn ($r): string => $r->item, $registry->blockTypes()));
        $critical = array_values(array_filter($registry->routes(), static fn ($r): bool => $r->item->critical));
        self::assertSame(['acme.events.add'], array_map(static fn ($r): string => $r->item->name, $critical));

        $jobs = new ModuleJobs();
        self::assertSame(['1 upcoming event checked for reminders.'], $jobs->run($services, new DateTimeImmutable()));
        self::assertSame([], $jobs->failures());

        $translations = Services::get($this->site->kernel->container(), Translations::class);
        self::assertSame('Eventos', $translations->translate(new Message('Events'), [], 'pt-PT', 'acme_events'));
        self::assertSame('“Open day” foi adicionado.', $translations->translate(new Message('Added “{title}”.', ['title' => 'Open day']), [], 'pt-PT', 'acme_events'));

        Services::get($services, EventDispatcherInterface::class)->dispatch(new PageTransitioned(7, 'publish', 'approved', 'published', null));
        self::assertSame(['Noticed that page 7 was published.'], $this->connection()->createQueryBuilder()->select('summary')->from('audit_log')->where("event_action = 'acme.events.page_seen'")->fetchFirstColumn());
    }

    public function testAVariantOfACoreBlockAndAnIcon(): void
    {
        $services = $this->site->container();
        $image = Services::get($services, BlockTypeCatalogue::class)->stylesOf('image');
        self::assertNotNull($image);
        self::assertSame(['plain', 'rounded', 'bordered', 'ticket'], array_map(static fn ($variant): string => $variant->name, $image->variants));

        $icons = Services::get($services, BlockIconCatalogue::class);
        self::assertSame('acme-tickets', $icons->forUrl('https://tickets.acme.test/open-day')?->name);
        self::assertStringContainsString('aria-hidden="true"', (string) $icons->svg('acme-tickets'));
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }

    private function connection(): Connection
    {
        return Services::get($this->site->container(), Connection::class);
    }
}
