<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PublicPage;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PublishedPages;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PublishedPages::class)]
#[CoversClass(PublicPage::class)]
#[UsesNamespace('SightPress\Core')]
final class PublishedPagesTest extends TestCase
{
    public function testFindsPublishedPublicPagesByTheirAddress(): void
    {
        $fixture = new PageFixture();
        $pages = new PublishedPages($fixture->repository(), $fixture->site);
        $blocks = [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hi', 'level' => 2]]];
        $about = $fixture->published('About', $blocks);
        $team = $fixture->published('Team', [], $about);
        $fixture->editor()->save($about, 'pt-PT', new PageContent('Sobre', 'sobre', null, null, $blocks));
        $fixture->editor()->create('en-GB', new PageContent('Draft'));
        $hidden = $fixture->published('Hidden');
        $hidden->setPublic(false, $fixture->clock->now());
        $fixture->repository()->flush();

        $found = $pages->findByUrl('/about');
        self::assertEquals(new PublicPage((int) $about->id(), 'en-GB', '/about', 'About', $blocks), $found);
        self::assertSame('/about/team', $pages->findByUrl('about/team/?x=1#top')?->url);
        self::assertSame((int) $team->id(), $pages->findByUrl('/about/team')?->id);
        self::assertEquals(new PublicPage((int) $about->id(), 'pt-PT', '/pt-pt/sobre', 'Sobre', $blocks), $pages->findByUrl('/pt-pt/sobre'));
        self::assertNull($pages->findByUrl('/pt-pt'));
        self::assertNull($pages->findByUrl('/'));
        self::assertNull($pages->findByUrl('/sobre'));
        self::assertNull($pages->findByUrl('/draft'));
        self::assertNull($pages->findByUrl('/hidden'));
        self::assertNull($pages->findByUrl('/missing'));
    }
}
