<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * Pages through a real site container and its own database: create a small tree, rename and move a branch, and
 * read it all back from a fresh entity manager with the revisions.
 */
#[CoversNothing]
final class PageTreeTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('pages');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testBuildsRenamesAndMovesABranch(): void
    {
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $pages = Services::get($container, PageRepository::class);

        $about = $editor->create('en-GB', new PageContent('About'), null, 1);
        $news = $editor->create('en-GB', new PageContent('News'), null, 1);
        $team = $editor->create('en-GB', new PageContent('Team'), $about, 1);
        $editor->create('en-GB', new PageContent('People'), $team, 1);
        $editor->save($about, 'en-GB', new PageContent('About us', 'about-us'), 1);
        $editor->move($team, $news, 0, 1);

        Services::get($container, EntityManagerInterface::class)->clear();

        $people = $pages->findByPath('en-GB', 'news/team/people');
        self::assertNotNull($people);
        self::assertSame(2, $people->page()->depth());
        self::assertNull($pages->findByPath('en-GB', 'about/team/people'));
        $found = $pages->find((int) $team->id());
        self::assertNotNull($found);
        self::assertSame([2, 1], array_map(static fn ($revision) => $revision->number(), $pages->revisions($found)));
        self::assertSame([], $pages->children($pages->find((int) $about->id())));
    }
}
