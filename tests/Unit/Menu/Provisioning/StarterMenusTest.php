<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu\Provisioning;

use DI\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Menu\MenuNode;
use SightPress\Core\Menu\Provisioning\StarterMenus;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Site\Contract\FirstAdmin;
use SightPress\Core\Site\Contract\SiteProvisioning;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\RecordingEvents;

#[CoversClass(StarterMenus::class)]
#[UsesNamespace('SightPress\Core')]
final class StarterMenusTest extends TestCase
{
    public function testPutsTheStarterPagesThatExistInEmptyMenus(): void
    {
        $pages = new PageFixture();
        foreach (['Home', 'About', 'Contact', 'Privacy', 'Accessibility'] as $title) {
            $pages->published($title);
        }
        $menus = new MenuEditor($pages->database->entityManager, new RecordingEvents());
        $menus->add(MenuName::Main, null, 'Shop', 'https://shop.example');
        $event = new SiteProvisioning($pages->site, new Container([
            MenuEditor::class => $menus,
            PageLinks::class => new PageLinkQuery($pages->database->entityManager, $pages->addresses()),
        ]), new FirstAdmin('a@example.org', 'A', 'B'), 'https://demo.example');

        new StarterMenus()($event);

        self::assertSame(['Shop'], array_map(static fn (MenuNode $node) => $node->item->label(), $menus->tree(MenuName::Main)), 'a menu with entries is left alone');
        self::assertSame([4, 5], array_map(static fn (MenuNode $node) => $node->item->pageId(), $menus->tree(MenuName::Footer)), 'Terms is missing');
        self::assertSame(['Added 2 starter pages to the main and footer menus.'], $event->lines());

        new StarterMenus()($event);
        self::assertCount(1, $event->lines(), 'nothing more to add, nothing said');
    }
}
