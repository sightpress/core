<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Slug;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\LocaleCode;

#[CoversClass(Page::class)]
#[UsesClass(PageTranslation::class)]
#[UsesClass(PageContent::class)]
#[UsesClass(Slug::class)]
#[UsesClass(LocaleCode::class)]
final class PageTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
    }

    public function testStartsAsAPublicDraftWithTheDefaultLayout(): void
    {
        $page = new Page(null, -3, 7, $this->now);

        self::assertNull($page->id());
        self::assertNull($page->parent());
        self::assertSame([0, 0, '', 7, PageState::Draft, true, 'default'], [$page->position(), $page->depth(), $page->treePath(), $page->authorId(), $page->state(), $page->isPublic(), $page->layout()]);
        self::assertSame([$this->now, $this->now], [$page->createdAt(), $page->updatedAt()]);
        self::assertSame([], $page->translations());
        self::assertNull($page->translation('en-GB'));
    }

    public function testKeepsItsPlaceInTheTree(): void
    {
        $top = self::withId(new Page(null, 0, null, $this->now), 3);
        $top->placeInTree();
        $child = self::withId(new Page($top, 2, null, $this->now), 12);
        $child->placeInTree();
        $grandchild = self::withId(new Page($child, 0, null, $this->now), 40);
        $grandchild->placeInTree();

        self::assertSame(['/3/', '/3/12/', '/3/12/40/'], [$top->treePath(), $child->treePath(), $grandchild->treePath()]);
        self::assertSame([0, 1, 2], [$top->depth(), $child->depth(), $grandchild->depth()]);
        self::assertSame($top, $child->parent());
        self::assertTrue($top->isAncestorOf($grandchild));
        self::assertFalse($top->isAncestorOf($top));
        self::assertFalse($grandchild->isAncestorOf($top));
        self::assertFalse(new Page(null, 0, null, $this->now)->isAncestorOf($top));

        $later = $this->now->modify('+1 hour');
        $other = self::withId(new Page(null, 1, null, $this->now), 5);
        $other->placeInTree();
        $child->moveTo($other, -1, $later);
        $grandchild->followParent();
        self::assertSame(['/5/12/', '/5/12/40/', 0, 1, 2], [$child->treePath(), $grandchild->treePath(), $child->position(), $child->depth(), $grandchild->depth()]);
        self::assertSame($later, $child->updatedAt());

        $child->moveTo(null, 4, $later);
        $grandchild->followParent();
        self::assertSame(['/12/', '/12/40/', 0, 1, 4], [$child->treePath(), $grandchild->treePath(), $child->depth(), $grandchild->depth(), $child->position()]);
        $top->followParent();
        self::assertSame('/3/', $top->treePath());

        $child->setPosition(-2);
        self::assertSame(0, $child->position());
    }

    public function testNeedsAnIdForItsPlaceInTheTree(): void
    {
        $this->expectException(PageException::class);
        $this->expectExceptionMessage('A page needs to be saved before it gets its place in the tree.');

        new Page(null, 0, null, $this->now)->placeInTree();
    }

    public function testNestsAtMostTenLevels(): void
    {
        $page = null;
        for ($level = 0; $level < Page::MAX_DEPTH; ++$level) {
            $page = new Page($page, 0, null, $this->now);
        }

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('Pages can be nested at most 10 levels deep.');

        new Page($page, 0, null, $this->now);
    }

    public function testIsWrittenPerLocale(): void
    {
        $page = new Page(null, 0, null, $this->now);
        $later = $this->now->modify('+1 hour');

        $english = $page->write('en-GB', new PageContent('About'), 'about', $this->now);
        $portuguese = $page->write('pt-PT', new PageContent('Sobre'), 'sobre', $this->now);
        $again = $page->write('en-GB', new PageContent('About us', 'about-us'), 'about-us', $later);

        self::assertSame($english, $again);
        self::assertSame(['en-GB' => $english, 'pt-PT' => $portuguese], $page->translations());
        self::assertSame($english, $page->translation('en-GB'));
        self::assertSame(['About us', 'about-us'], [$english->title(), $english->path()]);
        self::assertSame($later, $page->updatedAt());
    }

    public function testChangesVisibilityAndLayout(): void
    {
        $page = new Page(null, 0, null, $this->now);
        $later = $this->now->modify('+1 hour');

        $page->setPublic(false, $later);
        $page->setLayout('home', $later);

        self::assertSame([false, 'home', $later], [$page->isPublic(), $page->layout(), $page->updatedAt()]);

        $this->expectException(PageException::class);
        $this->expectExceptionMessage('"Home Page" is not a layout name such as "default" or "home".');
        $page->setLayout('Home Page', $later);
    }

    public function testChangesState(): void
    {
        $page = new Page(null, 0, null, $this->now);
        $later = $this->now->modify('+1 hour');

        $page->changeState(\SightPress\Core\Rbac\Contract\PageState::InReview, $later);

        self::assertSame([\SightPress\Core\Rbac\Contract\PageState::InReview, $later], [$page->state(), $page->updatedAt()]);
    }

    public function testSnapshotsEverythingARevisionKeeps(): void
    {
        $parent = self::withId(new Page(null, 0, null, $this->now), 3);
        $page = new Page($parent, 1, null, $this->now);
        $page->write('pt-PT', new PageContent('Sobre', null, 'Resumo'), 'inicio/sobre', $this->now);
        $page->write('en-GB', new PageContent('About', null, null, 'Who we are', [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi']]]), 'home/about', $this->now);

        self::assertSame(
            [
                'parent' => 3,
                'position' => 1,
                'layout' => 'default',
                'public' => true,
                'state' => 'draft',
                'translations' => [
                    'en-GB' => ['title' => 'About', 'slug' => 'about', 'path' => 'home/about', 'summary' => null, 'description' => 'Who we are', 'blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi']]]],
                    'pt-PT' => ['title' => 'Sobre', 'slug' => 'sobre', 'path' => 'inicio/sobre', 'summary' => 'Resumo', 'description' => null, 'blocks' => []],
                ],
            ],
            $page->snapshot(),
        );
    }

    private static function withId(Page $page, int $id): Page
    {
        new ReflectionProperty(Page::class, 'id')->setValue($page, $id);

        return $page;
    }
}
