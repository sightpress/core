<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Page\Admin\Http\PageScheduleHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tests\Support\PageAdminFixture;
use SightPress\Core\User\Entity\User;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PageScheduleHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class PageScheduleHandlerTest extends TestCase
{
    private PageAdminFixture $fixture;

    private User $editor;

    private Page $page;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->editor = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->page = $this->fixture->pages->editor()->create('en-GB', new PageContent('Talk'));
        $this->page->changeState(PageState::Approved, $this->fixture->pages->clock->now());
    }

    public function testSchedulesInThePersonsTimeZoneAndSaysSo(): void
    {
        $response = $this->send(['publish_at' => '2026-12-01T09:00', 'unpublish_at' => '2026-12-02 18:30']);

        self::assertSame([303, PageAdmin::editPath((int) $this->page->id())], [$response->getStatusCode(), $response->getHeaderLine('Location')]);
        self::assertSame(['Scheduled: the page is published at 2026-12-01 09:00 and taken off the site at 2026-12-02 18:30 (Europe/Lisbon time).'], $this->flash());
        self::assertSame('2026-12-01 09:00 UTC', $this->page->publishAt()?->format('Y-m-d H:i T'));

        $this->send(['publish_at' => '2026-12-05T10:00', 'unpublish_at' => '']);
        self::assertSame(['Scheduled: the page is published at 2026-12-05 10:00 (Europe/Lisbon time).'], $this->flash());
        $this->send(['publish_at' => '', 'unpublish_at' => '2026-12-06T10:00']);
        self::assertSame(['Scheduled: the page is taken off the site at 2026-12-06 10:00 (Europe/Lisbon time).'], $this->flash());
        $this->send(['op' => 'clear', 'publish_at' => 'ignored']);
        self::assertSame(['The schedule is cleared: nothing happens to the page on its own.'], $this->flash());
        self::assertNull($this->page->unpublishAt());
    }

    public function testExplainsWhatIsWrong(): void
    {
        $back = $this->send(['publish_at' => 'soon', 'unpublish_at' => '2026-12-02T10:00']);
        self::assertSame(['Publish at: enter a date and time, for example 2026-12-01 09:00.'], $this->flash());
        self::assertSame(PageAdmin::editPath((int) $this->page->id()) . '?publish_at=soon&unpublish_at=2026-12-02T10%3A00', $back->getHeaderLine('Location'));
        $request = $this->fixture->admin->request($this->editor, 'GET', $back->getHeaderLine('Location'), [], $this->fixture->session)
            ->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $this->page->id());
        $html = (string) $this->fixture->editorHandler()->handle($request)->getBody();
        self::assertStringContainsString('name="publish_at" value="soon"', $html);
        self::assertStringContainsString('name="unpublish_at" value="2026-12-02T10:00"', $html);
        self::assertSame(PageAdmin::editPath((int) $this->page->id()) . '?publish_at=2026-11-01T09%3A00', $this->send(['publish_at' => '2026-11-01T09:00'])->getHeaderLine('Location'));
        self::assertSame(['Choose a time to publish that is still to come.'], $this->flash());
        $this->send(['op' => 'save', 'publish_at' => '', 'unpublish_at' => '', 'x' => 1]);
        $this->flash();
        $this->page->changeState(PageState::Archived, new \DateTimeImmutable());
        self::assertSame(PageAdmin::editPath((int) $this->page->id()), $this->send(['publish_at' => [], 'unpublish_at' => ''])->getHeaderLine('Location'));
        $this->page->changeState(PageState::Approved, new \DateTimeImmutable());
        $this->flash();
        $this->send(['unpublish_at' => ['not', 'text']]);
        self::assertSame(['The schedule is cleared: nothing happens to the page on its own.'], $this->flash());
    }

    public function testOnlyPeopleWhoMayPublishSchedule(): void
    {
        $contributor = $this->fixture->admin->auth->user('cy@example.org', 'contributor');
        $own = $this->fixture->pages->editor()->create('en-GB', new PageContent('Mine'), null, (int) $contributor->id());
        try {
            $this->send(['publish_at' => '2026-12-01T09:00'], $contributor, $own);
            self::fail('A contributor scheduled a page.');
        } catch (HttpForbiddenException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(HttpNotFoundException::class);
        $this->send([], $contributor);
    }

    public function testTheEditorShowsTheScheduleAndTheListSaysItInWords(): void
    {
        $this->send(['publish_at' => '2026-12-01T09:00']);
        $request = $this->fixture->admin->request($this->editor, 'GET', '/admin/pages/' . $this->page->id(), [], $this->fixture->session)
            ->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $this->page->id())
            ->withAttribute(Viewer::class, new Viewer('en-GB', 'Europe/Lisbon'));

        $html = (string) $this->fixture->editorHandler()->handle($request)->getBody();
        self::assertStringContainsString('<h2 id="schedule-title">Schedule</h2>', $html);
        self::assertStringContainsString('value="2026-12-01T09:00"', $html);
        self::assertStringContainsString('Times are in your time zone, Europe/Lisbon.', $html);
        self::assertStringContainsString('To be published at', $html);
        self::assertStringContainsString('Clear the schedule', $html);

        $list = (string) $this->fixture->handle($this->fixture->listHandler(), $this->editor, 'GET', '/admin/pages')->getBody();
        self::assertStringContainsString('Approved · To be published at', $list);

        $this->page->changeState(PageState::Published, new \DateTimeImmutable());
        $this->fixture->pages->repository()->flush();
        $published = (string) $this->fixture->editorHandler()->handle($request)->getBody();
        self::assertStringNotContainsString('id="schedule-publish"', $published);
        self::assertStringContainsString('Nothing is scheduled.', $published);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(array $body, ?User $user = null, ?Page $page = null): ResponseInterface
    {
        $page ??= $this->page;
        $request = $this->fixture->admin->request($user ?? $this->editor, 'POST', '/admin/pages/' . $page->id() . '/schedule', $body, $this->fixture->session)
            ->withAttribute(PageAdmin::ID_ATTRIBUTE, (string) $page->id())
            ->withAttribute(Viewer::class, new Viewer('en-GB', 'Europe/Lisbon'));

        return $this->fixture->scheduleHandler()->handle($request);
    }

    /**
     * @return list<string>
     */
    private function flash(): array
    {
        return array_map(strval(...), Flash::take($this->fixture->session));
    }
}
