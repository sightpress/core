<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Admin\AnalyticsCsvHandler;
use SightPress\Core\Analytics\Admin\AnalyticsHandler;
use SightPress\Core\Analytics\Admin\AnalyticsWidget;
use SightPress\Core\Analytics\Admin\DeleteAnalyticsHandler;
use SightPress\Core\Analytics\Admin\RetentionHandler;
use SightPress\Core\Analytics\AnalyticsRetention;
use SightPress\Core\Analytics\AnalyticsRollup;
use SightPress\Core\Analytics\AnalyticsStore;
use SightPress\Core\Analytics\Contract\AnalyticsDataDeleted;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * The Analytics screen, its CSV downloads, retention and deletion, and the dashboard widget, through the kernel
 * with people of each role.
 */
#[CoversClass(AnalyticsHandler::class)]
#[CoversClass(AnalyticsCsvHandler::class)]
#[CoversClass(RetentionHandler::class)]
#[CoversClass(DeleteAnalyticsHandler::class)]
#[CoversClass(AnalyticsWidget::class)]
#[CoversClass(AnalyticsDataDeleted::class)]
#[UsesNamespace('SightPress\Core')]
final class AnalyticsAdminTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private string $today;

    protected function setUp(): void
    {
        $this->site = new KernelSite('analytics_admin', locales: ['en-GB', 'pt-PT']);
        $container = $this->site->container();
        $page = Services::get($container, PageEditor::class)->create('en-GB', new PageContent('Opening hours', 'hours'));
        $page->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($container, PageRepository::class)->flush();
        $this->today = Services::get($container, AnalyticsRollup::class)->today(new DateTimeImmutable());
        $yesterday = new DateTimeImmutable($this->today)->modify('-1 day')->format('Y-m-d');
        $figure = static fn (string $metric, string $dimension, string $item, int $value): array => ['metric' => $metric, 'dimension' => $dimension, 'item' => $item, 'value' => $value];
        Services::get($container, AnalyticsStore::class)->finishDay($yesterday, [
            $figure('visitors', '', '', 12),
            $figure('views', '', '', 30),
            $figure('views', 'page', $page->id() . ':en-GB', 20),
            $figure('visitors', 'page', $page->id() . ':en-GB', 10),
            $figure('views', 'page', '999:pt-PT', 10),
            $figure('visitors', 'source', 'search:Google', 4),
            $figure('visitors', 'locale', 'pt-PT', 2),
            $figure('visitors', 'device', 'phone', 5),
            $figure('not_found', 'path', '/old <news>', 3),
            $figure('bot_reads', 'bot', 'GPTBot|ai_training|llms', 2),
            $figure('searches', '', '', 2),
            $figure('searches', 'query', 'parking', 2),
            $figure('empty_searches', '', '', 2),
            $figure('empty_searches', 'query', 'parking', 2),
        ]);
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnAdminSeesEveryReportAndTheSettings(): void
    {
        $ada = $this->browser('ada@example.org', 'admin');

        $html = (string) $ada->request('GET', '/admin/analytics')->getBody();
        self::assertStringContainsString('<title>Analytics: Last 30 days', $html);
        self::assertStringContainsString('<a href="/admin/analytics?range=30" aria-current="page">Last 30 days</a>', $html);
        foreach (['Visitors over time', 'Pages read most', 'Where visitors came from', 'Languages', 'Devices', 'Site search', 'Pages not found', 'Search engines and AI crawlers', 'Keeping analytics'] as $heading) {
            self::assertStringContainsString('">' . $heading . '</h2>', $html, $heading);
        }
        self::assertStringContainsString('12 visitors, none in the 30 days before', $html);
        self::assertStringContainsString('<a href="/" lang="en-GB">Opening hours</a>', $html);
        self::assertStringContainsString('A page that was deleted (number 999)', $html);
        self::assertStringContainsString('Google (search engine)', $html);
        self::assertMatchesRegularExpression('#<span lang="pt-PT">[^<]+</span>#', $html, 'the languages report');
        self::assertStringContainsString('2 searches, 2 of which found nothing.', $html);
        self::assertStringContainsString('&lt;news&gt;', $html);
        self::assertStringContainsString('href="/admin/redirects?from=%2Fold+%3Cnews%3E"', $html);
        self::assertMatchesRegularExpression('#<input type="number" id="analytics-retention-months" value="25" name="months"#', $html);

        $week = (string) $ada->request('GET', '/admin/analytics?range=7')->getBody();
        self::assertStringContainsString('<a href="/admin/analytics?range=7" aria-current="page">', $week);
        $bad = (string) $ada->request('GET', '/admin/analytics?from=2026-02-01&to=2026-01-01')->getBody();
        self::assertStringContainsString('The last day must be the same as the first day or after it', $bad);
        self::assertStringContainsString('class="error-summary"', $bad);

        $redirect = (string) $ada->request('GET', '/admin/redirects?from=%2Fold-news')->getBody();
        self::assertStringContainsString('value="/old-news"', $redirect, 'the address comes along');
    }

    public function testDownloadsEachReportAsCsv(): void
    {
        $ada = $this->browser('ada@example.org', 'admin');

        $daily = $ada->request('GET', '/admin/analytics/download?report=daily&range=7');
        self::assertSame('text/csv; charset=utf-8', $daily->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="analytics-demo-daily-', $daily->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith("\"day (Europe/London)\",visitors,views\r\n", (string) $daily->getBody());
        $notFound = (string) $ada->request('GET', '/admin/analytics/download?report=not-found')->getBody();
        self::assertMatchesRegularExpression('#^"path \(\d{4}-\d\d-\d\d to ' . $this->today . ', Europe/London\)",requests\r\n"/old <news>",3\r\n$#', $notFound);
        self::assertSame(404, $ada->request('GET', '/admin/analytics/download?report=visits')->getStatusCode());
    }

    public function testRetentionAndDeletionAreForAdmins(): void
    {
        $ada = $this->browser('ada@example.org', 'admin');

        self::assertSame(303, $ada->submit('/admin/analytics', ['months' => '13'], RetentionHandler::PATH)->getStatusCode());
        self::assertSame(13, Services::get($this->site->container(), AnalyticsRetention::class)->months());
        self::assertStringContainsString('Analytics are now kept for 13 months.', (string) $ada->request('GET', '/admin/analytics')->getBody());
        $ada->submit('/admin/analytics', ['months' => '40'], RetentionHandler::PATH);
        self::assertStringContainsString('Choose how many months to keep analytics for, from 1 to 25.', (string) $ada->request('GET', '/admin/analytics')->getBody());

        $confirm = (string) $ada->request('GET', DeleteAnalyticsHandler::PATH)->getBody();
        self::assertStringContainsString('Delete all analytics data?', $confirm);
        self::assertMatchesRegularExpression('/Every figure counted since \d{4}-\d\d-\d\d is deleted for good/', $confirm);
        self::assertSame(303, $ada->submit(DeleteAnalyticsHandler::PATH, [])->getStatusCode());
        self::assertNull(Services::get($this->site->container(), AnalyticsStore::class)->firstDay());
        self::assertStringContainsString('Nothing has been counted yet', (string) $ada->request('GET', DeleteAnalyticsHandler::PATH)->getBody());
        self::assertStringContainsString('Deleted all analytics data.', (string) $ada->request('GET', '/admin/audit')->getBody());

        $bea = $this->browser('bea@example.org', 'editor');
        $screen = (string) $bea->request('GET', '/admin/analytics')->getBody();
        self::assertStringNotContainsString('Keeping analytics', $screen);
        self::assertSame(403, $bea->request('GET', DeleteAnalyticsHandler::PATH)->getStatusCode());
        self::assertSame(403, $bea->submit('/admin/analytics', ['months' => '1'], RetentionHandler::PATH)->getStatusCode());
    }

    public function testContributorsHaveNoAnalytics(): void
    {
        $cleo = $this->browser('cleo@example.org', 'contributor');

        self::assertSame(403, $cleo->request('GET', '/admin/analytics')->getStatusCode());
        self::assertSame(403, $cleo->request('GET', '/admin/analytics/download?report=pages')->getStatusCode());
        $dashboard = (string) $cleo->request('GET', '/admin')->getBody();
        self::assertStringNotContainsString('Last 7 days', $dashboard);
        self::assertStringNotContainsString('href="/admin/analytics"', $dashboard);
    }

    public function testTheDashboardShowsTheLastSevenDays(): void
    {
        $widget = Services::get($this->site->container(), AnalyticsWidget::class);
        self::assertSame(['analytics.summary', 'Last 7 days', 'analytics.view', 10], [$widget->id(), $widget->title(), $widget->permission(), $widget->position()]);
        $bea = $this->browser('bea@example.org', 'editor');

        $html = (string) $bea->request('GET', '/admin')->getBody();
        self::assertStringContainsString('<h2 id="dashboard-widget-analytics-summary">Last 7 days</h2>', $html);
        self::assertStringContainsString('<p>12 visitors and 30 page views in the last 7 days; visitors none in the 7 days before.</p><p><a href="/admin/analytics">See all analytics</a></p>', $html);

        Services::get($this->site->container(), AnalyticsStore::class)->deleteAll();
        self::assertStringContainsString('Nothing has been counted in the last 7 days yet.', (string) $bea->request('GET', '/admin')->getBody());
    }

    public function testWithSearchOffThereIsNoSearchReportAndOneLanguageHasNoLanguages(): void
    {
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOff($this->site->site(), 'search', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');
        $html = (string) $this->browser('ada@example.org', 'admin')->request('GET', '/admin/analytics')->getBody();

        self::assertStringNotContainsString('">Site search</h2>', $html);
    }

    private function browser(string $email, string $role): Browser
    {
        $container = $this->site->container();
        $user = new User($email, ucfirst((string) strstr($email, '@', true)), 'Tester', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? self::fail($role));
        $browser = new Browser($this->site->kernel, 'https://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
