<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Module\Event\PageScheduled;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Module\Event\PageTransitioned;
use SightPress\Module\Event\ScheduledStepFailed;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Schedule\LocalTime;
use SightPress\Core\Page\Schedule\PageSchedule;
use SightPress\Core\Page\Schedule\ScheduledPages;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\ScheduleDue;
use SightPress\Core\Template\Message;
use SightPress\Core\Tests\Support\ArrayContainer;
use SightPress\Core\Tests\Support\PageAdminFixture;

#[CoversClass(PageSchedule::class)]
#[CoversClass(ScheduledPages::class)]
#[CoversClass(ScheduleDue::class)]
#[UsesNamespace('SightPress\Core')]
final class PageScheduleTest extends TestCase
{
    private PageAdminFixture $fixture;

    private PageSchedule $schedule;

    private Actor $editor;

    protected function setUp(): void
    {
        $this->fixture = new PageAdminFixture();
        $this->schedule = $this->schedule();
        $user = $this->fixture->admin->auth->user('bea@example.org', 'editor');
        $this->editor = new Actor((int) $user->id(), $this->fixture->admin->auth->database->roles()->permissionNamesOf((int) $user->id()));
    }

    public function testPublishesOnTheFirstRunAfterItsTimeAcrossAChangeOfTheClocks(): void
    {
        // 01:30 on the night the clocks go back in London is 01:30 UTC (the second, winter-time one).
        $publishAt = LocalTime::parse('2026-10-25T01:30', 'Europe/London', new Message('Publish at')) ?? self::fail('no time');
        $clock = $this->fixture->pages->clock;
        $clock->advance(new DateTimeImmutable('2026-10-24 12:00:00 UTC')->getTimestamp() - $clock->now()->getTimestamp());
        $page = $this->approved('Autumn opening hours');
        $this->schedule->set($page, $publishAt, null, $this->editor);
        self::assertSame([$publishAt, $this->editor->userId], [$page->publishAt(), $page->scheduledBy()]);

        self::assertSame([], $this->schedule->run(new DateTimeImmutable('2026-10-25 01:29:00 UTC')));
        self::assertSame(PageState::Approved, $page->state());
        self::assertSame(['Published “Autumn opening hours” on schedule.'], $this->schedule->run(new DateTimeImmutable('2026-10-25 01:31:00 UTC')));
        self::assertSame(PageState::Published, $page->state());
        self::assertNull($page->publishAt());
        self::assertSame([], $this->schedule->run(new DateTimeImmutable('2026-10-25 01:32:00 UTC')), 'a second run does nothing');

        $published = array_values(array_filter($this->fixture->pages->events, static fn (object $event): bool => $event instanceof PageTransitioned));
        self::assertEquals([new PageTransitioned((int) $page->id(), 'publish', 'approved', 'published', $this->editor->userId, 'Published on schedule.')], $published);
    }

    public function testAMissedRunCatchesUpAndTakesPagesOffTheSite(): void
    {
        $now = $this->fixture->pages->clock->now();
        $page = $this->approved('Summer fair');
        $this->schedule->set($page, $now->modify('+1 hour'), $now->modify('+2 days'), $this->editor);
        $waiting = $this->fixture->pages->editor()->create('en-GB', new PageContent('Not approved yet'));
        $this->schedule->set($waiting, $now->modify('+1 hour'), null, $this->editor);

        // The server was off for a day: the next run publishes it, and the draft waits for its approval.
        self::assertSame(['Published “Summer fair” on schedule.'], $this->schedule->run($now->modify('+1 day')));
        self::assertNotNull($waiting->publishAt());
        self::assertSame(['Took “Summer fair” off the site on schedule.'], $this->schedule->run($now->modify('+3 days')));
        self::assertSame([PageState::Draft, null, null], [$page->state(), $page->unpublishAt(), $page->scheduledBy()]);
    }

    public function testAStepThatCantBeTakenIsReportedAndCleared(): void
    {
        $now = $this->fixture->pages->clock->now();
        $blocked = $this->approved('Deep', [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Deep', 'level' => 4]]]);
        $this->schedule->set($blocked, $now->modify('+1 hour'), $now->modify('+2 hours'), $this->editor);
        $contributor = $this->fixture->admin->auth->user('cy@example.org', 'contributor');
        $orphan = $this->fixture->pages->published('Old news');
        $orphan->schedule(null, $now->modify('+1 hour'), (int) $contributor->id(), $now);
        $nobody = $this->fixture->pages->published('Nobody’s');
        $nobody->schedule(null, $now->modify('+1 hour'), null, $now);
        $bare = new Page(null, 9, null, $now);
        $bare->changeState(PageState::Approved, $now);
        $bare->schedule($now->modify('+1 hour'), null, $this->editor->userId, $now);
        $this->fixture->pages->repository()->add($bare);
        $this->fixture->pages->repository()->flush();
        $this->fixture->pages->events = [];

        $lines = $this->schedule->run($now->modify('+3 hours'));

        self::assertSame([
            'Couldn’t publish “Deep” on schedule: the publish checks found errors. Its publishing time was cleared.',
            sprintf('Published “page %d” on schedule.', $bare->id()),
            'Couldn’t take “Old news” off the site on schedule: You don\'t have permission to do "Unpublish" on this page. Its end time was cleared.',
            'Couldn’t take “Nobody’s” off the site on schedule: You don\'t have permission to do "Unpublish" on this page. Its end time was cleared.',
        ], $lines);
        self::assertSame([null, null], [$blocked->publishAt(), $orphan->unpublishAt()]);
        self::assertNotNull($blocked->unpublishAt(), 'the end time stays');
        self::assertContainsEquals(new ScheduledStepFailed((int) $blocked->id(), PageTransition::Publish->value, 'the publish checks found errors', $this->editor->userId), $this->fixture->pages->events);
    }

    public function testChecksWhoSchedulesAndTheTimes(): void
    {
        $now = $this->fixture->pages->clock->now();
        $page = $this->approved('Talk');
        $later = $now->modify('+1 day');
        foreach ([
            [new Actor(9, ['page.edit.any']), $later, null, 'Only people who may publish pages can schedule them.'],
            [$this->editor, $now, null, 'Choose a time to publish that is still to come.'],
            [$this->editor, null, $now->modify('-1 minute'), 'Choose a time to take the page off the site that is still to come.'],
            [$this->editor, $later, $later, 'The page must be taken off the site after it is published.'],
        ] as [$actor, $publishAt, $unpublishAt, $message]) {
            try {
                $this->schedule->set($page, $publishAt, $unpublishAt, $actor);
                self::fail('Accepted: ' . $message);
            } catch (PageException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $this->schedule->set($page, $later, $later->modify('+1 day'), $this->editor);
        $this->schedule->set($page, null, null, $this->editor);
        self::assertSame([null, null, null], [$page->publishAt(), $page->unpublishAt(), $page->scheduledBy()]);
        self::assertEquals(new PageScheduled((int) $page->id(), null, null, $this->editor->userId), end($this->fixture->pages->events));

        $page->changeState(PageState::Published, $now);
        $this->schedule->set($page, null, $later, $this->editor);
        $this->assertRefused($page, $later, 'The page is published already, so it can only get a time to be taken off the site.');
        $page->changeState(PageState::Archived, $now);
        $this->assertRefused($page, $later, 'Archived pages and pages in the trash can’t be scheduled. Restore the page first.');
        $this->schedule->set($page, null, null, $this->editor);
    }

    public function testRunsAsTheJobOfThePageModule(): void
    {
        $now = $this->fixture->pages->clock->now();
        $page = $this->approved('Concert');
        $this->schedule->set($page, $now->modify('+1 minute'), null, $this->editor);
        $due = new ScheduleDue($this->fixture->pages->site, new ArrayContainer([PageSchedule::class => $this->schedule]), $now->modify('+2 minutes'));

        new ScheduledPages()($due);

        self::assertSame(['Published “Concert” on schedule.'], $due->lines());
    }

    private function assertRefused(Page $page, DateTimeImmutable $publishAt, string $message): void
    {
        try {
            $this->schedule->set($page, $publishAt, null, $this->editor);
            self::fail('Accepted: ' . $message);
        } catch (PageException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param list<mixed> $blocks
     */
    private function approved(string $title, array $blocks = []): Page
    {
        $page = $this->fixture->pages->editor()->create('en-GB', new PageContent($title, null, null, null, $blocks));
        $page->changeState(PageState::Approved, $this->fixture->pages->clock->now());
        $this->fixture->pages->repository()->flush();

        return $page;
    }

    private function schedule(): PageSchedule
    {
        return new PageSchedule(
            $this->fixture->pages->repository(),
            $this->fixture->workflow(),
            $this->fixture->admin->auth->database->roles(),
            $this->fixture->pages->clock,
            $this->fixture->pages,
            $this->fixture->pages->site,
        );
    }
}
