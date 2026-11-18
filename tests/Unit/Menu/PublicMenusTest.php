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
use SightPress\Core\Page\RedirectedAddresses;
use SightPress\Core\Rbac\Contract\Actor;
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
        $pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
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
        $listener = $this->listener($pages, $menus);

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
        self::assertSame([['label' => 'Sobre', 'url' => '/pt-pt/sobre', 'current' => true, 'children' => []]], self::entries($portuguese, 'main_menu'));
        self::assertSame([['label' => null, 'url' => '/pt-pt'], ['label' => 'Sobre', 'url' => '/pt-pt/sobre']], self::entries($portuguese, 'breadcrumbs'), 'pages that aren\'t public are left out');

        $front = $this->event((int) $home->id(), 'en-GB', '/', 'Welcome', [], true);
        $listener($front);
        self::assertArrayNotHasKey('breadcrumbs', $front->variables());
    }

    public function testMembersSeeMembersEntriesAndVisitorsDont(): void
    {
        $pages = new PageFixture();
        $pages->published('Welcome');
        $club = $pages->published('Book club');
        $club->setPublic(false, $pages->clock->now());
        $notes = $pages->published('Notes', [], $club);
        $pages->repository()->flush();
        $menus = new MenuEditor($pages->database->entityManager, new RecordingEvents());
        $menus->add(MenuName::Main, (int) $club->id());
        $forum = $menus->add(MenuName::Main, null, 'Forum', 'https://forum.example');
        $forum->describe('Forum', [], true);
        $listener = $this->listener($pages, $menus);

        $visitor = $this->event((int) $notes->id(), 'en-GB', '/book-club/notes', 'Notes', [(int) $club->id()], false);
        $listener($visitor);
        self::assertSame([], self::entries($visitor, 'main_menu'));
        self::assertSame([['label' => null, 'url' => '/'], ['label' => 'Notes', 'url' => '/book-club/notes']], self::entries($visitor, 'breadcrumbs'));

        $member = $this->event((int) $notes->id(), 'en-GB', '/book-club/notes', 'Notes', [(int) $club->id()], false, new Actor(7));
        $listener($member);
        self::assertSame(['Book club', 'Forum'], array_column(self::entries($member, 'main_menu'), 'label'));
        self::assertSame(['Book club', 'Notes'], array_column(array_slice(self::entries($member, 'breadcrumbs'), 1), 'label'));
    }

    public function testEntriesHaveTheirTextInEachLanguageAndLinksFollowMovedPages(): void
    {
        $pages = new PageFixture();
        $pages->published('Welcome');
        $about = $pages->published('About');
        $pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $pages->redirects()->add('en-GB', 'old-about', 'about');
        $pages->redirects()->gone('en-GB', 'closed');
        $menus = new MenuEditor($pages->database->entityManager, new RecordingEvents());
        $entry = $menus->add(MenuName::Main, (int) $about->id(), 'Who we are');
        $entry->describe('Who we are', ['pt-PT' => 'Quem somos'], false);
        $menus->add(MenuName::Main, (int) $about->id());
        $moved = $menus->add(MenuName::Footer, null, 'Our story', '/old-about#history');
        $moved->describe('Our story', ['pt-PT' => 'A nossa história'], false);
        $menus->add(MenuName::Footer, null, 'Closed', '/closed');
        $menus->add(MenuName::Footer, null, 'Elsewhere', 'https://example.org/x');
        $listener = $this->listener($pages, $menus);

        $english = $this->event((int) $about->id(), 'en-GB', '/about', 'About', [], false);
        $listener($english);
        self::assertSame([['Who we are', true], ['About', true]], array_map(static fn (array $e): array => [$e['label'], $e['current']], self::entries($english, 'main_menu')));
        self::assertSame([['Our story', '/about#history'], ['Elsewhere', 'https://example.org/x']], array_map(static fn (array $e): array => [$e['label'], $e['url']], self::entries($english, 'footer_menu')), 'a link to a deleted page is left out');

        $portuguese = $this->event((int) $about->id(), 'pt-PT', '/pt-pt/sobre', 'Sobre', [], false);
        $listener($portuguese);
        self::assertSame([['Quem somos', true], ['Sobre', true]], array_map(static fn (array $e): array => [$e['label'], $e['current']], self::entries($portuguese, 'main_menu')), 'its own text, else the page title in that language; current in every language');
        self::assertSame(['A nossa história', 'Elsewhere'], array_column(self::entries($portuguese, 'footer_menu'), 'label'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(PublicPageRendering $event, string $name): array
    {
        $entries = $event->variables()[$name] ?? null;
        self::assertIsArray($entries);

        /** @var list<array<string, mixed>> */
        return $entries;
    }

    private function listener(PageFixture $pages, MenuEditor $menus): PublicMenus
    {
        return new PublicMenus($menus, new PageLinkQuery($pages->database->entityManager, $pages->addresses(), $pages->database->settings()), new RedirectedAddresses($pages->addresses(), $pages->redirects()), $pages->site);
    }

    /**
     * @param list<int> $ancestors
     */
    private function event(int $id, string $locale, string $url, string $title, array $ancestors, bool $home, ?Actor $actor = null): PublicPageRendering
    {
        $request = new ServerRequest('GET', $url);

        return new PublicPageRendering($actor === null ? $request : $request->withAttribute(Actor::class, $actor), $id, $locale, $url, 'https://demo.example', $title, null, null, $ancestors, $home, true, new DateTimeImmutable());
    }
}
