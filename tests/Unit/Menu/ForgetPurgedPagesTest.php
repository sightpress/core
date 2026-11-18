<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\ForgetPurgedPages;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Page\Contract\PagePurged;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\RecordingEvents;

#[CoversClass(ForgetPurgedPages::class)]
#[UsesNamespace('SightPress\Core')]
final class ForgetPurgedPagesTest extends TestCase
{
    public function testTheEntriesOfPagesDeletedForGoodGoToo(): void
    {
        $menus = new MenuEditor(new CoreSiteDatabase()->entityManager, new RecordingEvents());
        $kept = $menus->add(MenuName::Main, 1);
        $menus->add(MenuName::Main, 2);
        $menus->add(MenuName::Footer, 3);

        new ForgetPurgedPages($menus)(new PagePurged(2, 'Old', [2, 3], 4));

        self::assertSame([$kept], $menus->items(MenuName::Main));
        self::assertSame([], $menus->items(MenuName::Footer));
    }
}
