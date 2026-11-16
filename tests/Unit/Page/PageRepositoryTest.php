<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tests\Support\CoreSiteDatabase;

#[CoversClass(PageRepository::class)]
#[UsesNamespace('SightPress\Core')]
final class PageRepositoryTest extends TestCase
{
    private CoreSiteDatabase $database;

    private PageRepository $pages;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->pages = new PageRepository($this->database->entityManager);
        $this->now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
    }

    public function testFindsPagesByIdPathAndPlaceInTheTree(): void
    {
        $about = $this->page(null, 0, 'about', 'about');
        $contact = $this->page(null, 1, 'contact', 'contact');
        $team = $this->page($about, 1, 'team', 'about/team');
        $history = $this->page($about, 0, 'history', 'about/history');
        $people = $this->page($team, 0, 'people', 'about/team/people');
        $this->database->entityManager->clear();

        $found = $this->pages->find((int) $about->id());
        self::assertNotNull($found);
        self::assertSame('/' . $about->id() . '/', $found->treePath());
        self::assertNull($this->pages->find(999));

        $translation = $this->pages->findByPath('en-GB', 'about/team');
        self::assertNotNull($translation);
        self::assertSame($team->id(), $translation->page()->id());
        self::assertNull($this->pages->findByPath('pt-PT', 'about/team'));

        self::assertSame([$about->id(), $contact->id()], array_map(static fn (Page $p) => $p->id(), $this->pages->children(null)));
        self::assertSame([$history->id(), $team->id()], array_map(static fn (Page $p) => $p->id(), $this->pages->children($found)));
        self::assertSame(
            [$history->id(), $team->id(), $people->id()],
            array_map(static fn (Page $p) => $p->id(), $this->pages->descendants($found)),
        );
        self::assertSame([], $this->pages->descendants(new Page(null, 0, null, $this->now)));
    }

    public function testKeepsRevisionsNewestFirst(): void
    {
        $page = $this->page(null, 0, 'about', 'about');
        self::assertSame(0, $this->pages->lastRevisionNumber($page));

        $first = new PageRevision($page, 1, null, $this->now);
        $second = new PageRevision($page, 2, 5, $this->now);
        $this->pages->add($first);
        $this->pages->add($second);
        $this->pages->flush();

        self::assertSame(2, $this->pages->lastRevisionNumber($page));
        self::assertSame([$second, $first], $this->pages->revisions($page));
        self::assertSame($first, $this->pages->revision($page, 1));
        self::assertNull($this->pages->revision($page, 3));
    }

    public function testRunsWorkInOneTransaction(): void
    {
        self::assertSame(42, $this->pages->transaction(static fn (): int => 42));

        try {
            $this->pages->transaction(function (): never {
                $this->pages->add($this->unsaved());
                $this->pages->flush();

                throw new RuntimeException('Stop.');
            });
        } catch (RuntimeException) {
        }

        self::assertEquals(0, $this->database->connection->createQueryBuilder()->select('COUNT(*)')->from('pages')->executeQuery()->fetchOne());
    }

    private function unsaved(): Page
    {
        $page = new Page(null, 0, null, $this->now);
        $page->write('en-GB', new PageContent('Lost'), 'lost', $this->now);

        return $page;
    }

    private function page(?Page $parent, int $position, string $slug, string $path): Page
    {
        $page = new Page($parent, $position, null, $this->now);
        $page->write('en-GB', new PageContent(ucfirst($slug), $slug), $path, $this->now);
        $this->pages->add($page);
        $this->pages->flush();
        $page->placeInTree();
        $this->pages->flush();

        return $page;
    }
}
