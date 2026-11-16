<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PageChange;
use SightPress\Core\Page\Contract\PageSummary;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageOverviewQuery;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageOverviewQuery::class)]
#[CoversClass(PageSummary::class)]
#[CoversClass(PageChange::class)]
#[UsesNamespace('SightPress\Core')]
final class PageOverviewQueryTest extends TestCase
{
    private PageFixture $fixture;

    private PageOverviewQuery $overview;

    protected function setUp(): void
    {
        $this->fixture = new PageFixture();
        $this->overview = new PageOverviewQuery($this->fixture->database->entityManager, $this->fixture->site);
    }

    public function testListsAPersonsDraftsNewestFirst(): void
    {
        $older = $this->page('Opening hours', 7);
        $this->fixture->clock->advance(60);
        $newer = $this->page('Events', 7);
        $this->page('Someone else’s draft', 8);
        $sent = $this->page('Sent for review', 7, PageState::InReview);
        $this->fixture->database->entityManager->clear();

        $drafts = $this->overview->draftsBy(7, 5);

        self::assertSame([$newer->id(), $older->id()], array_map(static fn (PageSummary $p): int => $p->id, $drafts));
        self::assertSame('Events', $drafts[0]->title);
        self::assertSame('en-GB', $drafts[0]->locale);
        self::assertSame(PageState::Draft, $drafts[0]->state);
        self::assertSame(7, $drafts[0]->authorId);
        self::assertEquals($this->fixture->clock->now(), $drafts[0]->updatedAt);
        self::assertCount(1, $this->overview->draftsBy(7, 1));
        self::assertSame([], $this->overview->draftsBy(99, 5));
        self::assertSame([$sent->id()], array_map(static fn (PageSummary $p): int => $p->id, $this->overview->awaitingReview(5)));
    }

    public function testUsesTheDefaultLanguageOrTheFirstOneThePageHas(): void
    {
        $page = $this->fixture->editor()->create('pt-PT', new PageContent('Sobre nós'), authorId: 7);
        $this->fixture->editor()->save($page, 'en-GB', new PageContent('About us'), 7);
        $portuguese = $this->fixture->editor()->create('pt-PT', new PageContent('Contactos'), authorId: 7);
        $this->fixture->database->entityManager->clear();

        $titles = array_map(static fn (PageSummary $p): array => [$p->locale, $p->title], $this->overview->draftsBy(7, 5));

        self::assertContains(['en-GB', 'About us'], $titles);
        self::assertContains(['pt-PT', 'Contactos'], $titles);
        self::assertNotNull($portuguese->id());
    }

    public function testListsTheLatestSavesWithWhoMadeThem(): void
    {
        $about = $this->page('About us', 7);
        $this->fixture->clock->advance(60);
        $this->fixture->editor()->save($about, 'en-GB', new PageContent('About the library'), 8);
        $this->fixture->clock->advance(60);
        $contact = $this->fixture->editor()->create('en-GB', new PageContent('Contact'));
        $this->fixture->database->entityManager->clear();

        $changes = $this->overview->recentChanges(2);

        self::assertSame(
            [[$contact->id(), 1, null], [$about->id(), 2, 8]],
            array_map(static fn (PageChange $c): array => [$c->page->id, $c->revision, $c->savedBy], $changes),
        );
        self::assertSame('About the library', $changes[1]->page->title);
        self::assertEquals($this->fixture->clock->now(), $changes[0]->savedAt);
        self::assertCount(3, $this->overview->recentChanges(10));
        self::assertSame([], new PageOverviewQuery(new PageFixture()->database->entityManager, $this->fixture->site)->recentChanges(5));
    }

    public function testNamesAPageWithoutAnyTranslation(): void
    {
        $page = new Page(null, 0, 7, $this->fixture->clock->now());
        $this->fixture->repository()->add($page);
        $this->fixture->repository()->flush();

        self::assertSame([['en-GB', 'Untitled page']], array_map(
            static fn (PageSummary $p): array => [$p->locale, $p->title],
            $this->overview->draftsBy(7, 5),
        ));
    }

    private function page(string $title, int $author, PageState $state = PageState::Draft): Page
    {
        $page = $this->fixture->editor()->create('en-GB', new PageContent($title), authorId: $author);
        $page->changeState($state, $this->fixture->clock->now());
        $this->fixture->repository()->flush();

        return $page;
    }
}
