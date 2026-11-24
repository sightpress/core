<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Module\Event\PageCreated;
use SightPress\Module\Event\PageMoved;
use SightPress\Module\Event\PageSaved;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\PageShift;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageEditor::class)]
#[CoversClass(PageShift::class)]
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

    public function testChecksAndCleansBlocksBeforeSaving(): void
    {
        $page = $this->editor->create('en-GB', new PageContent('About', null, null, null, [
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Hi<script>x</script></p>']],
        ]));
        self::assertSame([['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Hi</p>']]], $page->translation('en-GB')?->blocks());

        try {
            $this->editor->save($page, 'en-GB', new PageContent('About', null, null, null, [['type' => 'heading', 'version' => 1, 'data' => ['text' => '']]]));
            self::fail('Expected the blocks to be refused.');
        } catch (InvalidBlocks $exception) {
            self::assertSame(['/0/data/text', '/0/data/level'], array_map(static fn ($violation): string => $violation->pointer, $exception->violations));
        }
        self::assertCount(1, $this->pages->revisions($page));

        $this->expectException(InvalidBlocks::class);
        $this->editor->create('en-GB', new PageContent('Contact', null, null, null, [['type' => 'acme.missing', 'version' => 1, 'data' => []]]));
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
     * @return array{?Page, ?string, int} the parent, the address and the position
     */
    private static function place(Page $page): array
    {
        return [$page->parent(), $page->translation('en-GB')?->path(), $page->position()];
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

    public function testANewTranslationOfAWrittenPageStartsAsADraftOfItsOwn(): void
    {
        $about = $this->fixture->published('About');
        self::assertFalse($about->translation('en-GB')?->isDraft());

        $this->editor->save($about, 'pt-PT', new PageContent('Sobre', 'sobre'));

        $translation = $about->translation('pt-PT');
        self::assertTrue($translation?->isDraft());
        self::assertFalse($translation->isPublished());
        $this->editor->save($about, 'pt-PT', new PageContent('Sobre nós', 'sobre'));
        $saved = $about->translation('pt-PT');
        self::assertTrue($saved?->isDraft(), 'saving keeps it a draft');
    }

    public function testTranslatesFromAnotherLanguageLeavingItAsItWas(): void
    {
        $blocks = [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi', 'level' => 2]]];
        $about = $this->editor->create('en-GB', new PageContent('About', 'about', 'Who we are', 'All about us', $blocks, seoTitle: 'About us'));
        $before = $about->translation('en-GB')?->snapshot();
        $this->fixture->events = [];

        $revision = $this->editor->translate($about, 'pt-PT', 'en-GB', 4);

        $copy = $about->translation('pt-PT');
        self::assertSame(['About', 'about', 'Who we are', 'All about us', $blocks, 'About us', true], [$copy?->title(), $copy?->slug(), $copy?->summary(), $copy?->description(), $copy?->blocks(), $copy?->seoTitle(), $copy?->isDraft()]);
        self::assertSame($before, $about->translation('en-GB')?->snapshot());
        self::assertEquals([new PageSaved((int) $about->id(), 'pt-PT', $revision->number(), 4)], $this->fixture->events);

        foreach ([
            ['pt-PT', 'en-GB', 'The page is already written in "pt-PT". Change that translation instead.'],
            ['es', 'en-GB', 'This site isn\'t written in "es".'],
        ] as [$locale, $from, $message]) {
            try {
                $this->editor->translate($about, $locale, $from);
                self::fail($message);
            } catch (PageException $exception) {
                self::assertStringStartsWith($message, $exception->getMessage());
            }
        }
    }

    public function testCantTranslateFromALanguageThePageIsntWrittenIn(): void
    {
        $fixture = new PageFixture(null, [], ['en-GB', 'pt-PT', 'es']);
        $about = $fixture->editor()->create('en-GB', new PageContent('About'));

        $this->expectExceptionMessage('The page isn\'t written in "pt-PT", so it can\'t be copied from it.');
        $fixture->editor()->translate($about, 'es', 'pt-PT');
    }

    public function testPublishesATranslationOnItsOwnAndMakesItADraftAgain(): void
    {
        $about = $this->fixture->published('About');
        $this->editor->translate($about, 'pt-PT', 'en-GB');
        $this->fixture->events = [];

        self::assertTrue($this->editor->publishTranslation($about, 'pt-PT', true, 5));
        self::assertTrue($about->translation('pt-PT')?->isPublished());
        self::assertFalse($this->editor->publishTranslation($about, 'pt-PT', true, 5));
        self::assertTrue($this->editor->publishTranslation($about, 'pt-PT', false, 5));
        self::assertTrue($about->translation('pt-PT')->isDraft());
        self::assertCount(2, $this->fixture->events);
        self::assertEquals(new \SightPress\Module\Event\TranslationPublished((int) $about->id(), 'pt-PT', true, 3, 5), $this->fixture->events[0]);
        self::assertSame(4, $this->pages->lastRevisionNumber($about));

        $this->expectExceptionMessage('The page isn\'t written in "pt-PT" yet.');
        $this->editor->publishTranslation($this->fixture->published('Contact'), 'pt-PT', true);
    }

    public function testOldAddressesOfPublishedPagesLeadToTheNewOnesInOneHop(): void
    {
        $about = $this->fixture->published('About');
        $contact = $this->fixture->published('Contact');
        $team = $this->fixture->published('Team', [], $about);
        $this->fixture->translated($about, 'pt-PT', new PageContent('Sobre'));
        $this->fixture->translated($team, 'pt-PT', new PageContent('Equipa'));
        $this->fixture->translated($contact, 'pt-PT', new PageContent('Contacto'));
        $people = $this->editor->create('en-GB', new PageContent('People'), $team);
        $redirects = $this->fixture->redirects();

        $this->editor->move($team, null, 9);

        self::assertSame(['team', 'equipa', 'team/people'], [$team->translation('en-GB')?->path(), $team->translation('pt-PT')?->path(), $people->translation('en-GB')?->path()]);
        self::assertSame('team', $redirects->find('en-GB', 'about/team')?->target());
        self::assertSame('equipa', $redirects->find('pt-PT', 'sobre/equipa')?->target());
        self::assertNull($redirects->find('en-GB', 'about/team/people'), 'a draft was never at its old address for visitors');

        $this->editor->move($team, $contact, 0);
        self::assertSame('contact/team', $redirects->find('en-GB', 'about/team')?->target(), 'chains collapse to one hop');
        self::assertSame('contact/team', $redirects->find('en-GB', 'team')?->target());
        self::assertSame('contacto/equipa', $redirects->find('pt-PT', 'sobre/equipa')?->target());

        $this->editor->move($team, null, 9);
        self::assertNull($redirects->find('en-GB', 'team'), 'the page took its old address back');
        self::assertSame('team', $redirects->find('en-GB', 'about/team')?->target());
        self::assertSame('team', $redirects->find('en-GB', 'contact/team')?->target());

        $this->editor->save($about, 'en-GB', new PageContent('About us', 'about-us'));
        self::assertSame('about-us', $redirects->find('en-GB', 'about')?->target());

        $this->editor->create('en-GB', new PageContent('About again', 'about'));
        self::assertNull($redirects->find('en-GB', 'about'), 'a new page on an old address wins');
    }

    public function testShiftsAPageOneStep(): void
    {
        [$a, $b, $c] = $this->topLevel('a', 'b', 'c');

        $this->editor->shift($c, PageShift::Up, 4);
        self::assertSame([0, 1, 2], [$a->position(), $c->position(), $b->position()]);
        $this->editor->shift($c, PageShift::Down);
        self::assertSame(2, $c->position());
        $this->editor->shift($c, PageShift::In);
        self::assertSame([$b, 'b/c', 0], self::place($c));
        $this->editor->shift($c, PageShift::Out);
        self::assertSame([null, 'c', 2], self::place($c));

        foreach ([
            [$a, PageShift::Up, 'The page is already the first one at its level.'],
            [$c, PageShift::Down, 'The page is already the last one at its level.'],
            [$a, PageShift::In, 'There is no page before this one to move it under.'],
            [$a, PageShift::Out, 'The page is already at the top level.'],
        ] as [$page, $shift, $message]) {
            try {
                $this->editor->shift($page, $shift);
                self::fail('Expected ' . $shift->value . ' to be refused.');
            } catch (PageException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
        self::assertSame(['Move up', 'Move down', 'Move under the page before it', 'Move out to the level above'], array_map(static fn (PageShift $shift): string => $shift->label(), PageShift::cases()));
    }

    public function testNeverMovesAPageUnderOneInTheTrash(): void
    {
        [$a, $b] = $this->topLevel('a', 'b');
        $a->changeState(PageState::Trash, $this->fixture->clock->now());

        $this->expectExceptionMessage('A page can\'t be moved under a page in the trash.');
        $this->editor->move($b, $a, 0);
    }

    public function testCopiesAPageRightAfterItInEveryLanguage(): void
    {
        $about = $this->fixture->published('About', [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hello', 'level' => 2]]]);
        $contact = $this->fixture->published('Contact');
        $this->fixture->translated($about, 'pt-PT', new PageContent('Sobre'));
        $about->setLayout('home', $this->fixture->clock->now());
        $about->setPublic(false, $this->fixture->clock->now());
        $this->fixture->events = [];

        $copy = $this->editor->copy($about, 6);
        $again = $this->editor->copy($about, 6);

        self::assertSame([PageState::Draft, 'home', false, 6], [$copy->state(), $copy->layout(), $copy->isPublic(), $copy->authorId()]);
        self::assertSame(['Copy of About', 'about-copy', 'Cópia de Sobre', 'sobre-copy'], [$copy->translation('en-GB')?->title(), $copy->translation('en-GB')?->path(), $copy->translation('pt-PT')?->title(), $copy->translation('pt-PT')?->path()]);
        self::assertSame($about->translation('en-GB')?->blocks(), $copy->translation('en-GB')?->blocks());
        self::assertFalse($copy->translation('pt-PT')?->isDraft());
        self::assertSame('about-copy-2', $again->translation('en-GB')?->slug());
        self::assertSame([0, 1, 2, 3], [$about->position(), $again->position(), $copy->position(), $contact->position()]);
        self::assertSame(1, $this->pages->lastRevisionNumber($copy));
        self::assertEquals([
            new PageCreated((int) $copy->id(), 'en-GB', 6),
            new \SightPress\Module\Event\PageCloned((int) $copy->id(), (int) $about->id(), 6),
        ], array_slice($this->fixture->events, 0, 2));
        self::assertSame('Hello', $about->translation('en-GB')?->blocks()[0]['data']['text'] ?? null, 'the original doesn\'t change');
    }

    public function testCopiesKeepTheSlugShortAndNeedALanguageOfTheSite(): void
    {
        $long = $this->editor->create('en-GB', new PageContent('Long', str_repeat('a', 60)));
        self::assertSame(str_repeat('a', 55) . '-copy', $this->editor->copy($long)->translation('en-GB')?->slug());

        $welsh = new PageFixture($this->fixture->database, [], ['cy']);
        $this->expectExceptionMessage('The page isn\'t written in any of the site\'s languages, so there is nothing to copy.');
        $welsh->editor()->copy($long);
    }

    public function testDeletesATrashedBranchForGoodLeavingItsAddressesGone(): void
    {
        [$a, $b, $c] = $this->topLevel('a', 'b', 'c');
        $child = $this->fixture->published('Child', [], $b);
        $this->fixture->translated($b, 'pt-PT', new PageContent('Bê', 'be'));
        $this->editor->save($b, 'en-GB', new PageContent('B', 'b'));
        $redirects = $this->fixture->redirects();
        $redirects->add('en-GB', 'old-b', 'b');
        $this->fixture->events = [];

        try {
            $this->editor->purge($b, 5);
            self::fail('Only pages in the trash are deleted.');
        } catch (PageException $exception) {
            self::assertSame('Only pages in the trash can be deleted for good, with every page under them.', $exception->getMessage());
        }
        $b->changeState(PageState::Trash, $this->fixture->clock->now());
        try {
            $this->editor->purge($b, 5);
            self::fail('The pages under it must be in the trash too.');
        } catch (PageException) {
        }
        $child->changeState(PageState::Trash, $this->fixture->clock->now());
        $ids = [(int) $b->id(), (int) $child->id()];

        $this->editor->purge($b, 5);

        $this->fixture->database->entityManager->clear();
        self::assertNull($this->pages->find($ids[0]));
        self::assertNull($this->pages->find($ids[1]));
        self::assertSame([0, 1], [$this->pages->find((int) $a->id())?->position(), $this->pages->find((int) $c->id())?->position()]);
        foreach ([['en-GB', 'b'], ['pt-PT', 'be'], ['en-GB', 'b/child'], ['en-GB', 'old-b']] as [$locale, $path]) {
            self::assertTrue($redirects->find($locale, $path)?->isGone(), $path . ' is gone');
        }
        self::assertEquals([new \SightPress\Module\Event\PagePurged($ids[0], 'B', $ids, 5)], $this->fixture->events);

        $page = $this->editor->create('en-GB', new PageContent('New B', 'b'));
        self::assertNull($redirects->find('en-GB', 'b'));
        $nameless = new Page(null, 0, null, $this->fixture->clock->now());
        $nameless->changeState(PageState::Trash, $this->fixture->clock->now());
        $this->pages->add($nameless);
        $this->pages->flush();
        $nameless->placeInTree();
        $this->pages->flush();
        $this->editor->purge($nameless);
        $last = end($this->fixture->events);
        self::assertInstanceOf(\SightPress\Module\Event\PagePurged::class, $last);
        self::assertSame('page ' . $nameless->id(), $last->title);
        self::assertSame(2, $page->position());
    }

    public function testRestoresAnOlderVersionAsANewRevisionWithoutPublishing(): void
    {
        $page = $this->editor->create('en-GB', new PageContent('About', 'about', 'Old summary', null, [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi', 'level' => 2]]]));
        $first = $this->pages->revisions($page)[0];
        $this->editor->save($page, 'en-GB', new PageContent('About us', 'about-us', null, 'New'));
        $this->editor->save($page, 'pt-PT', new PageContent('Sobre'));
        $this->fixture->events = [];

        $restored = $this->editor->restore($page, $first, 'en-GB', 7);

        $translation = $page->translation('en-GB');
        self::assertSame(['About', 'about', 'Old summary', null, 'Hi'], [$translation?->title(), $translation?->slug(), $translation?->summary(), $translation?->description(), $translation?->blocks()[0]['data']['text'] ?? null]);
        self::assertSame([4, PageState::Draft], [$restored->number(), $page->state()]);
        self::assertEquals([
            new PageSaved((int) $page->id(), 'en-GB', 4, 7),
            new \SightPress\Module\Event\RevisionRestored((int) $page->id(), 'en-GB', 1, 4, 7),
        ], $this->fixture->events);

        // A version of another page is refused.
        $other = $this->editor->create('en-GB', new PageContent('Other'));
        $this->editor->save($other, 'pt-PT', new PageContent('Outra'));
        $portuguese = $this->pages->revisions($other)[0];
        $copy = $this->editor->create('en-GB', new PageContent('Copy'));
        self::assertNotNull($copy->id());
        $this->expectException(PageException::class);
        $this->editor->restore($copy, $portuguese, 'pt-PT');
    }

    public function testRestoresATranslationThatVisitorsCantReadAndRefusesOneTheyCan(): void
    {
        $page = $this->fixture->published('News');
        $this->editor->save($page, 'pt-PT', new PageContent('Notícias', 'noticias'));
        $version = $this->pages->revisions($page)[0];
        $this->editor->save($page, 'pt-PT', new PageContent('Novidades', 'novidades'));

        $this->editor->restore($page, $version, 'pt-PT');
        self::assertSame(['Notícias', true], [$page->translation('pt-PT')?->title(), $page->translation('pt-PT')?->isDraft()]);

        try {
            $this->editor->restore($page, $version, 'en-GB');
            self::fail('A published translation was replaced.');
        } catch (PageException $exception) {
            self::assertStringStartsWith('Visitors can read this translation now', $exception->getMessage());
        }
        try {
            $this->editor->restore($page, $this->pages->revisions($page)[3], 'pt-PT');
            self::fail('A version without the translation was restored.');
        } catch (PageException $exception) {
            self::assertSame('Version 1 of the page isn\'t written in "pt-PT".', $exception->getMessage());
        }
    }

    public function testRestoresOldSnapshotsWithMissingFields(): void
    {
        $page = $this->editor->create('en-GB', new PageContent('About', 'about', null, null, [], null, 'SEO', true, 'https://example.org/a'));
        $revision = $this->pages->revisions($page)[0];
        new \ReflectionProperty(\SightPress\Core\Page\Entity\PageRevision::class, 'snapshot')->setValue($revision, ['translations' => ['en-GB' => ['title' => 'Old', 'blocks' => 'broken']]]);

        $this->editor->restore($page, $revision, 'en-GB');

        $translation = $page->translation('en-GB');
        self::assertSame(['Old', 'old', null, false, null], [$translation?->title(), $translation?->slug(), $translation?->seoTitle(), $translation?->isNoindex(), $translation?->canonicalUrl()]);
    }
}
