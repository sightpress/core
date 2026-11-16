<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Menu\PublicMenus;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;

#[CoversClass(PublicMenus::class)]
#[UsesNamespace('SightPress\Core')]
final class PublicMenusTest extends TestCase
{
    public function testPutsTheVisibleEntriesAndTheBreadcrumbsOnThePage(): void
    {
        $pages = new PageFixture();
        $home = $pages->published('Welcome');
        $about = $pages->published('About');
        $pages->editor()->save($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $team = $pages->published('Team', [], $about);
        $draft = $pages->editor()->create('en-GB', new PageContent('Draft'));
        $draftChild = $pages->published('Under the draft', [], $draft);
        $menus = new MenuEditor($pages->database->entityManager, new RecordingEvents());
        $aboutEntry = $menus->add(MenuName::Main, (int) $about->id());
        $menus->add(MenuName::Main, (int) $team->id(), 'The team', parent: $aboutEntry);
        $draftEntry = $menus->add(MenuName::Main, (int) $draft->id());
        $menus->add(MenuName::Main, (int) $draftChild->id(), parent: $draftEntry);
        $menus->add(MenuName::Main, 9999);
        $menus->add(MenuName::Footer, null, 'Hours', '/hours');
        $listener = new PublicMenus($menus, new PageLinkQuery($pages->database->entityManager, $pages->addresses()), $pages->site);

        $event = $this->event((int) $team->id(), 'en-GB', '/about/team', 'Team', [(int) $about->id()], false);
        $listener($event);

        self::assertSame([
            'main_menu' => [['label' => 'About', 'url' => '/about', 'current' => false, 'children' => [
                ['label' => 'The team', 'url' => '/about/team', 'current' => true, 'children' => []],
            ]]],
            'footer_menu' => [['label' => 'Hours', 'url' => '/hours', 'current' => false, 'children' => []]],
            'breadcrumbs' => [
                ['label' => null, 'url' => '/'],
                ['label' => 'About', 'url' => '/about'],
                ['label' => 'Team', 'url' => '/about/team'],
            ],
        ], $event->variables());

        $portuguese = $this->event((int) $about->id(), 'pt-PT', '/pt-pt/sobre', 'Sobre', [(int) $draft->id()], false);
        $listener($portuguese);
        self::assertSame([['label' => 'Sobre', 'url' => '/pt-pt/sobre', 'current' => true, 'children' => []]], $portuguese->variables()['main_menu']);
        self::assertSame([['label' => null, 'url' => '/pt-pt'], ['label' => 'Sobre', 'url' => '/pt-pt/sobre']], $portuguese->variables()['breadcrumbs'], 'pages that aren\'t public are left out');

        $front = $this->event((int) $home->id(), 'en-GB', '/', 'Welcome', [], true);
        $listener($front);
        self::assertArrayNotHasKey('breadcrumbs', $front->variables());
    }

    /**
     * @param list<int> $ancestors
     */
    private function event(int $id, string $locale, string $url, string $title, array $ancestors, bool $home): PublicPageRendering
    {
        return new PublicPageRendering(new ServerRequest('GET', $url), $id, $locale, $url, 'https://demo.example', $title, null, null, $ancestors, $home, true, new DateTimeImmutable());
    }
}
