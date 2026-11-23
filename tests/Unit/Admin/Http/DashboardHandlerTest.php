<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use SightPress\Core\Template\Message;
use SightPress\Core\Theme\Contract\ThemeStatus;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Admin\Http\DashboardHandler;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Http\Upload\ServerLimitsCheck;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageOverviewQuery;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\Assistant\NoAssistant;
use SightPress\Core\Tests\Support\AdminFixture;
use SightPress\Core\Tests\Support\Dashboard\FakeWidgets;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\ServerLimitsFixture;
use SightPress\Core\User\Entity\User;

#[CoversClass(DashboardHandler::class)]
#[CoversClass(\SightPress\Core\Admin\Http\AdminDashboardContext::class)]
#[CoversClass(\SightPress\Core\Infrastructure\Dashboard\DashboardWidgets::class)]
#[UsesNamespace('SightPress\Core')]
final class DashboardHandlerTest extends TestCase
{
    private AdminFixture $admin;

    private PageFixture $pages;

    protected function setUp(): void
    {
        $this->admin = new AdminFixture();
        $this->pages = new PageFixture($this->admin->auth->database);
    }

    public function testContributorsSeeTheirDraftsAndTheLatestChanges(): void
    {
        $cleo = $this->admin->auth->user('cleo@example.org', 'contributor');
        $this->page('Opening hours', $cleo);
        $this->page('Summer reading', $cleo, PageState::InReview);
        $this->pages->editor()->create('en-GB', new PageContent('Made by the console'));

        $html = $this->dashboard($cleo);

        self::assertStringContainsString('<h2 id="dashboard-drafts">Your drafts</h2>', $html);
        self::assertStringContainsString('lang="en-GB">Opening hours</a>', $html);
        self::assertStringNotContainsString('lang="en-GB">Summer reading</a>', $html);
        self::assertStringNotContainsString('Waiting for review', $html);
        self::assertStringContainsString('Ada saved “Opening hours”', $html);
        self::assertStringContainsString('“Made by the console” was saved', $html);
        self::assertMatchesRegularExpression('#<time datetime="2026-11-14T12:00:00Z">[^<]+</time>#', $html);
    }

    public function testEditorsSeeThePagesWaitingForReview(): void
    {
        $ada = $this->admin->auth->user();
        $cleo = $this->admin->auth->user('cleo@example.org', 'contributor');
        $this->page('Summer reading', $cleo, PageState::InReview);

        $html = $this->dashboard($ada);

        self::assertStringContainsString('You have no drafts.', $html);
        self::assertStringContainsString('<h2 id="dashboard-reviews">Waiting for review</h2>', $html);
        self::assertStringContainsString('lang="en-GB">Summer reading</a>', $html);
    }

    public function testPeopleWhoDoNotWritePagesSeeOnlyTheActivity(): void
    {
        $max = $this->admin->auth->user('max@example.org', null);

        $html = $this->dashboard($max);

        self::assertStringNotContainsString('Your drafts', $html);
        self::assertStringNotContainsString('Waiting for review', $html);
        self::assertStringContainsString('Nothing has changed yet.', $html);
        self::assertStringNotContainsString('No pages are waiting for review.', $html);
    }

    public function testAdminsSeeServerSettingsThatStopUploads(): void
    {
        $ada = $this->admin->auth->user('ada@example.org', 'admin');
        $cleo = $this->admin->auth->user('cleo@example.org', 'contributor');

        $html = $this->dashboard($ada, ServerLimitsFixture::phpDefaults());
        self::assertStringContainsString('<section class="alert alert--warning server-problems" aria-labelledby="server-problems-title">', $html);
        self::assertStringContainsString('<h2 id="server-problems-title">Your server’s settings stop some uploads</h2>', $html);
        self::assertStringContainsString('Files larger than 2 MB can’t be uploaded because of your server’s settings', $html);
        self::assertStringContainsString('href="https://docs.sightpress.org/latest/how-to/fix-upload-size-limits/"', $html);

        self::assertStringNotContainsString('server-problems', $this->dashboard($cleo, ServerLimitsFixture::phpDefaults()), 'only for people who manage the settings');
        self::assertStringNotContainsString('server-problems', $this->dashboard($ada), 'nothing to fix');
    }

    public function testAdminsSeeWhenTheSiteFallsBackToTheDefaultTheme(): void
    {
        $ada = $this->admin->auth->user('ada@example.org', 'admin');
        $cleo = $this->admin->auth->user('cleo@example.org', 'contributor');
        $problem = new Message('The theme {theme} is not installed on the server any more.', ['theme' => 'acme-gone']);

        $html = $this->dashboard($ada, theme: $problem);
        self::assertStringContainsString('<h2 id="theme-problem-title">Your site is shown in the default theme</h2>', $html);
        self::assertStringContainsString('<p>The theme acme-gone is not installed on the server any more. Visitors see the default theme until an admin chooses another one.</p>', $html);
        self::assertStringNotContainsString('theme-problem', $this->dashboard($cleo, theme: $problem), 'only for people who apply themes');
        self::assertStringNotContainsString('theme-problem', $this->dashboard($ada), 'nothing to fix');
    }

    public function testShowsTheWidgetsOfModulesThePersonMaySeeFirst(): void
    {
        $widgets = new FakeWidgets();
        $max = $this->admin->auth->user('max@example.org', null);

        $html = $this->dashboard($max, widgets: $widgets);

        self::assertStringContainsString('<h2 id="dashboard-widget-acme-news">Latest news</h2>', $html);
        self::assertStringContainsString('<p>News for en-GB in Europe/London, <a href="/admin/news/7">read</a>.</p>', $html);
        self::assertStringNotContainsString('Money', $html, 'needs acme.money');
        self::assertStringNotContainsString('Broken', $html, 'a widget that fails is left out');
        self::assertLessThan(strpos($html, 'Recent activity'), strpos($html, 'Latest news'));
        self::assertTrue($widgets->log->hasErrorThatContains('Dashboard widget {widget} failed'));
        self::assertSame(['widget' => 'acme.broken', 'class' => \RuntimeException::class, 'message' => 'No news today.'], $widgets->log->getRecords()[0]->context);
    }

    private function dashboard(User $user, ?ServerLimits $server = null, ?FakeWidgets $widgets = null, ?Message $theme = null): string
    {
        $widgets ??= new FakeWidgets();
        $handler = new DashboardHandler(
            $this->admin->shell(),
            new PageOverviewQuery($this->admin->auth->database->entityManager, $this->pages->site),
            $this->admin->auth->users,
            ServerLimitsFixture::notice($server ?? ServerLimitsFixture::standard(), $this->admin->auth->database->connection, $this->admin->auth->database->settings(), $this->admin->auth->clock),
            $this->admin->shell(),
            FakeWidgets::widgets(),
            $widgets->logger(),
            NoAssistant::access(),
            new readonly class ($theme) implements ThemeStatus {
                public function __construct(private ?Message $problem)
                {
                }

                public function problem(): ?Message
                {
                    return $this->problem;
                }
            },
        );

        return (string) $handler->handle($this->admin->request($user))->getBody();
    }

    private function page(string $title, User $author, PageState $state = PageState::Draft): Page
    {
        $page = $this->pages->editor()->create('en-GB', new PageContent($title), authorId: $author->id());
        $page->changeState($state, $this->pages->clock->now());
        $this->pages->repository()->flush();

        return $page;
    }
}
