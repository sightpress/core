<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PageLink;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(PageLinkQuery::class)]
#[CoversClass(PageLink::class)]
#[UsesNamespace('SightPress\Core')]
final class PageLinkQueryTest extends TestCase
{
    public function testFindsPagesAsLinksInALocale(): void
    {
        $fixture = new PageFixture();
        $home = $fixture->published('Welcome');
        $about = $fixture->published('About');
        $draft = $fixture->editor()->create('en-GB', new PageContent('Draft'));
        $team = $fixture->published('Team', [], $about);
        $fixture->editor()->move($draft, null, 1);
        $fixture->editor()->save($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $links = new PageLinkQuery($fixture->database->entityManager, $fixture->addresses());

        self::assertEquals([
            (int) $home->id() => new PageLink((int) $home->id(), 'Welcome', '/', 0, true),
            (int) $team->id() => new PageLink((int) $team->id(), 'Team', '/about/team', 1, true),
        ], $links->find([(int) $team->id(), (int) $home->id(), (int) $team->id(), 999], 'en-GB'));
        self::assertSame([], $links->find([], 'en-GB'));
        self::assertEquals([new PageLink((int) $about->id(), 'Sobre', '/pt-pt/sobre', 0, true)], array_values($links->find([(int) $about->id(), (int) $team->id()], 'pt-PT')));

        self::assertSame(
            ['Welcome', 'Draft', 'About', 'Team'],
            array_map(static fn (PageLink $link): string => $link->title, $links->all('en-GB')),
        );
        self::assertFalse($links->all('en-GB')[1]->public);
    }
}
