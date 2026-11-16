<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PageCreated;
use SightPress\Core\Page\Contract\PageMoved;
use SightPress\Core\Page\Contract\PageSaved;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageEditor::class)]
#[CoversClass(PageCreated::class)]
#[CoversClass(PageSaved::class)]
#[CoversClass(PageMoved::class)]
#[UsesNamespace('SightPress\Core')]
final class PageEditorTest extends TestCase
{
    private PageFixture $fixture;

    private PageEditor $editor;

    private PageRepository $pages;

    protected function setUp(): void
    {
        $this->fixture = new PageFixture();
        $this->editor = $this->fixture->editor();
        $this->pages = $this->fixture->repository();
    }

    public function testCreatesPagesAtTheEndOfTheirParentWithAFirstRevision(): void
    {
        $about = $this->editor->create('en-GB', new PageContent('About'), null, 3);
        $contact = $this->editor->create('en-GB', new PageContent('Contact'));
        $team = $this->editor->create('en-GB', new PageContent('Our team', 'team'), $about, 3);

        self::assertSame([0, 1, 0], [$about->position(), $contact->position(), $team->position()]);
        self::assertSame('/' . $about->id() . '/' . $team->id() . '/', $team->treePath());
        self::assertSame('about/team', $team->translation('en-GB')?->path());
        self::assertSame(3, $team->authorId());
        $revisions = $this->pages->revisions($team);
        self::assertCount(1, $revisions);
        self::assertSame([1, 3], [$revisions[0]->number(), $revisions[0]->savedBy()]);
        self::assertEquals(
            [
                new PageCreated((int) $about->id(), 'en-GB', 3),
                new PageSaved((int) $about->id(), 'en-GB', 1, 3),
                new PageCreated((int) $contact->id(), 'en-GB', null),
                new PageSaved((int) $contact->id(), 'en-GB', 1, null),
                new PageCreated((int) $team->id(), 'en-GB', 3),
                new PageSaved((int) $team->id(), 'en-GB', 1, 3),
            ],
            $this->fixture->events,
        );
    }

    public function testKeepsSlugsUniquePerParentAndLocale(): void
    {
        $about = $this->editor->create('en-GB', new PageContent('About'));
        $news = $this->editor->create('en-GB', new PageContent('News'));
        $this->editor->create('en-GB', new PageContent('Team'), $about);
        $this->editor->save($about, 'pt-PT', new PageContent('Sobre', 'about'));

        $this->editor->create('en-GB', new PageContent('Team'), $news);
        $this->editor->save($news, 'pt-PT', new PageContent('Notícias'));
        $this->editor->create('pt-PT', new PageContent('Team'), $about);

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The address "/about/team" is already used by the page "Team". Choose another one.');
        $this->editor->create('en-GB', new PageContent('Our team', 'team'), $about);
    }

    public function testRefusesSystemAddressesAtTheTopButNotBelow(): void
    {
        $about = $this->editor->create('en-GB', new PageContent('About'));
        $admin = $this->editor->create('en-GB', new PageContent('Admin'), $about);
        self::assertSame('about/admin', $admin->translation('en-GB')?->path());

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The address "pt" is used by the system. Choose another one for a top-level page.');
        $this->editor->create('en-GB', new PageContent('Portugal', 'pt'));
    }

    public function testOnlyWritesInTheSiteLocales(): void
    {
        $this->expectException(PageException::class);
        $this->expectExceptionMessage('This site isn\'t written in "fr-FR". Its languages are: en-GB, pt-PT.');

        $this->editor->create('fr-FR', new PageContent('Accueil'));
    }

    public function testATranslationNeedsTheParentInTheSameLocale(): void
    {
        $about = $this->editor->create('en-GB', new PageContent('About'));

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The page above this one isn\'t written in "pt-PT" yet. Translate it first.');

        $this->editor->create('pt-PT', new PageContent('Equipa'), $about);
    }

    public function testNestsAtMostTenLevelsWithTheLongestAddressesFitting(): void
    {
        $page = null;
        for ($level = 0; $level < 10; ++$level) {
            $page = $this->editor->create('en-GB', new PageContent('Level ' . $level, str_repeat('a', 59) . $level), $page);
        }
        self::assertNotNull($page);
        self::assertSame(609, strlen((string) $page->translation('en-GB')?->path()));

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('Pages can be nested at most 10 levels deep.');
        $this->editor->create('en-GB', new PageContent('Last'), $page);
    }

    public function testSavingANewSlugMovesTheAddressesBelowInThatLocaleOnly(): void
    {
        $about = $this->editor->create('en-GB', new PageContent('About'));
        $this->editor->save($about, 'pt-PT', new PageContent('Sobre'));
        $team = $this->editor->create('en-GB', new PageContent('Team'), $about);
        $this->editor->save($team, 'pt-PT', new PageContent('Equipa'));
        $people = $this->editor->create('en-GB', new PageContent('People'), $team);
        $this->fixture->events = [];

        $revision = $this->editor->save($about, 'en-GB', new PageContent('About us', 'about-us', 'Who we are'), 9);

        self::assertSame([3, 9], [$revision->number(), $revision->savedBy()]);
        self::assertSame(['about-us/team', 'sobre/equipa', 'about-us/team/people'], [
            $team->translation('en-GB')?->path(),
            $team->translation('pt-PT')?->path(),
            $people->translation('en-GB')?->path(),
        ]);
        self::assertSame('Who we are', $about->translation('en-GB')?->summary());
        self::assertEquals([new PageSaved((int) $about->id(), 'en-GB', 3, 9)], $this->fixture->events);

        $this->fixture->database->entityManager->clear();
        self::assertNotNull($this->pages->findByPath('en-GB', 'about-us/team/people'));
        self::assertNull($this->pages->findByPath('en-GB', 'about/team'));
    }

    public function testSavingTheSameSlugKeepsTheAddressAndRevisesThePage(): void
    {
        $about = $this->editor->create('en-GB', new PageContent('About'));

        $revision = $this->editor->save($about, 'en-GB', new PageContent('About', null, null, 'Description'));

        self::assertSame(2, $revision->number());
        self::assertSame('about', $about->translation('en-GB')?->path());
        $snapshot = $revision->snapshot();
        self::assertIsArray($snapshot['translations']);
        self::assertIsArray($snapshot['translations']['en-GB']);
        self::assertSame('Description', $snapshot['translations']['en-GB']['description']);
    }

    public function testReordersPagesUnderTheSameParent(): void
    {
        [$a, $b, $c] = $this->topLevel('a', 'b', 'c');

        $revision = $this->editor->move($c, null, 0, 4);

        self::assertSame([[$c, 0], [$a, 1], [$b, 2]], [[$c, $c->position()], [$a, $a->position()], [$b, $b->position()]]);
        self::assertSame(2, $revision->number());
        self::assertEquals([new PageMoved((int) $c->id(), null, null, 0, 2, 4)], $this->fixture->events);

        $this->editor->move($c, null, 99);
        self::assertSame([0, 1, 2], [$a->position(), $b->position(), $c->position()]);
    }

    public function testMovesABranchUnderAnotherParent(): void
    {
        [$about, $news] = $this->topLevel('about', 'news');
        $this->editor->save($about, 'pt-PT', new PageContent('Sobre'));
        $this->editor->save($news, 'pt-PT', new PageContent('Noticias'));
        $team = $this->editor->create('en-GB', new PageContent('Team'), $about);
        $this->editor->save($team, 'pt-PT', new PageContent('Equipa'));
        $history = $this->editor->create('en-GB', new PageContent('History'), $about);
        $people = $this->editor->create('en-GB', new PageContent('People'), $team);
        $archive = $this->editor->create('en-GB', new PageContent('Archive'), $news);
        $this->fixture->events = [];

        $this->editor->move($team, $news, 0, 2);

        self::assertSame($news, $team->parent());
        self::assertSame(['news/team', 'noticias/equipa', 'news/team/people'], [
            $team->translation('en-GB')?->path(),
            $team->translation('pt-PT')?->path(),
            $people->translation('en-GB')?->path(),
        ]);
        self::assertSame([
            '/' . $news->id() . '/' . $team->id() . '/',
            '/' . $news->id() . '/' . $team->id() . '/' . $people->id() . '/',
        ], [$team->treePath(), $people->treePath()]);
        self::assertSame([0, 1, 0], [$team->position(), $archive->position(), $history->position()]);
        self::assertEquals([new PageMoved((int) $team->id(), $about->id(), $news->id(), 0, 3, 2)], $this->fixture->events);

        $this->fixture->database->entityManager->clear();
        self::assertNotNull($this->pages->findByPath('en-GB', 'news/team/people'));
        $found = $this->pages->find((int) $news->id());
        self::assertNotNull($found);
        self::assertSame(['team', 'archive'], array_map(
            static fn (Page $page): string => (string) $page->translation('en-GB')?->slug(),
            $this->pages->children($found),
        ));

        $team = $this->pages->find((int) $team->id());
        self::assertNotNull($team);
        $this->editor->move($team, null, 1);
        self::assertSame(['team', 'equipa', 0, 1], [
            $team->translation('en-GB')?->path(),
            $team->translation('pt-PT')?->path(),
            $team->depth(),
            $team->position(),
        ]);
    }

    public function testNeverMovesAPageUnderItself(): void
    {
        [$about] = $this->topLevel('about');
        $team = $this->editor->create('en-GB', new PageContent('Team'), $about);

        foreach ([$about, $team] as $parent) {
            try {
                $this->editor->move($about, $parent, 0);
                self::fail('A page can\'t go under itself.');
            } catch (PageException $exception) {
                self::assertSame('A page can\'t be moved under itself or under one of its own subpages.', $exception->getMessage());
            }
        }
    }

    public function testKeepsTheDepthLimitWhenMovingABranch(): void
    {
        [$branch, $deep] = $this->topLevel('branch', 'deep');
        $this->editor->create('en-GB', new PageContent('Leaf'), $branch);
        $page = $deep;
        for ($level = 1; $level < 9; ++$level) {
            $page = $this->editor->create('en-GB', new PageContent('Level ' . $level), $page);
        }

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('Pages can be nested at most 10 levels deep.');
        $this->editor->move($branch, $page, 0);
    }

    public function testMovingNeedsTheNewParentInEveryLocaleAndAFreeAddress(): void
    {
        [$about, $news] = $this->topLevel('about', 'news');
        $this->editor->save($about, 'pt-PT', new PageContent('Sobre'));
        $team = $this->editor->create('en-GB', new PageContent('Team'), $about);
        $this->editor->save($team, 'pt-PT', new PageContent('Equipa'));

        try {
            $this->editor->move($team, $news, 0);
            self::fail('News has no pt-PT translation.');
        } catch (PageException $exception) {
            self::assertSame('The page above this one isn\'t written in "pt-PT" yet. Translate it first.', $exception->getMessage());
        }

        $this->editor->save($news, 'pt-PT', new PageContent('Noticias'));
        $this->editor->create('en-GB', new PageContent('Team'), $news);
        $this->expectException(PageException::class);
        $this->expectExceptionMessage('The address "/news/team" is already used by the page "Team".');
        $this->editor->move($team, $news, 0);
    }

    /**
     * @return list<Page>
     */
    private function topLevel(string ...$slugs): array
    {
        $pages = [];
        foreach ($slugs as $slug) {
            $pages[] = $this->editor->create('en-GB', new PageContent(ucfirst($slug), $slug));
        }
        $this->fixture->events = [];

        return $pages;
    }
}
