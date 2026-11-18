<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Menu;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Menu\Contract\MenuChanged;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\Entity\MenuItem;
use SightPress\Core\Menu\MenuEditor;
use SightPress\Core\Menu\MenuException;
use SightPress\Core\Menu\MenuNode;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\RecordingEvents;

#[CoversClass(MenuEditor::class)]
#[CoversClass(MenuNode::class)]
#[CoversClass(MenuChanged::class)]
#[UsesNamespace('SightPress\Core')]
final class MenuEditorTest extends TestCase
{
    private CoreSiteDatabase $database;

    private RecordingEvents $events;

    private MenuEditor $menus;

    protected function setUp(): void
    {
        $this->database = new CoreSiteDatabase();
        $this->events = new RecordingEvents();
        $this->menus = new MenuEditor($this->database->entityManager, $this->events);
    }

    public function testAddsEntriesAtTheEndAndBuildsTheTree(): void
    {
        $about = $this->menus->add(MenuName::Main, 1, actorId: 7);
        $team = $this->menus->add(MenuName::Main, 2, parent: $about);
        $hours = $this->menus->add(MenuName::Main, null, 'Hours', '/hours');
        $this->menus->add(MenuName::Footer, 3);

        self::assertSame($about, $this->menus->find((int) $about->id()));
        self::assertSame(['1', '2', 'Hours'], $this->labels(MenuName::Main));
        $tree = $this->menus->tree(MenuName::Main);
        self::assertSame([$about, $hours], array_map(static fn (MenuNode $node) => $node->item, $tree));
        self::assertSame($team, $tree[0]->children[0]->item);
        self::assertSame([1, 2], MenuNode::pageIds($tree));
        self::assertSame(1, $hours->position());
        self::assertEquals(new MenuChanged(MenuName::Main, MenuChanged::ADDED, (int) $about->id(), 1, null, 7), $this->events->events[0]);
    }

    public function testMovesEntriesUpAndDown(): void
    {
        [$a, $b, $c] = [$this->menus->add(MenuName::Main, 1), $this->menus->add(MenuName::Main, 2), $this->menus->add(MenuName::Main, 3)];

        $this->menus->moveUp($c, 5);
        self::assertSame(['1', '3', '2'], $this->labels(MenuName::Main));
        $this->menus->moveDown($a);
        self::assertSame(['3', '1', '2'], $this->labels(MenuName::Main));
        self::assertSame(MenuChanged::MOVED_DOWN, $this->last()->change);

        $this->expectExceptionMessage('This entry is already the first at its level.');
        $this->menus->moveUp($c);
        unset($b);
    }

    public function testTheLastEntryCannotMoveDown(): void
    {
        $this->menus->add(MenuName::Main, 1);
        $last = $this->menus->add(MenuName::Main, 2);

        $this->expectExceptionMessage('This entry is already the last at its level.');
        $this->menus->moveDown($last);
    }

    public function testIndentsAndOutdentsUpToThreeLevels(): void
    {
        [$a, $b, $c, $d] = [$this->menus->add(MenuName::Main, 1), $this->menus->add(MenuName::Main, 2), $this->menus->add(MenuName::Main, 3), $this->menus->add(MenuName::Main, 4)];

        $this->menus->indent($b);
        $this->menus->indent($c);
        self::assertSame($a, $b->parent());
        self::assertSame($a, $c->parent());
        self::assertSame([0, 1], [$b->position(), $c->position()]);
        self::assertSame(1, $d->position(), 'the top level closes up');
        $this->menus->indent($c);
        self::assertSame($b, $c->parent());
        $e = $this->menus->add(MenuName::Main, 5, parent: $d);
        $this->menus->add(MenuName::Main, 6, parent: $e);
        $this->assertRefused(fn () => $this->menus->indent($d), 'Menus can be at most 3 levels deep.');
        $this->menus->remove($e);

        $this->menus->outdent($b);
        self::assertNull($b->parent());
        self::assertSame([$a, $b, $d], array_map(static fn (MenuNode $node) => $node->item, $this->menus->tree(MenuName::Main)));
        self::assertSame($b, $c->parent(), 'entries under the one that moved go with it');
        self::assertSame(MenuChanged::OUTDENTED, $this->last()->change);
        $this->assertRefused(fn () => $this->menus->outdent($a), 'This entry is already at the top level.');
        $this->assertRefused(fn () => $this->menus->indent($a), 'The first entry at a level can\'t be moved in: there is no entry before it to go under.');
        $third = $this->menus->add(MenuName::Main, 8, parent: $c);
        $this->assertRefused(fn () => $this->menus->add(MenuName::Main, 9, parent: $third), 'Menus can be at most 3 levels deep.');
        $this->assertRefused(fn () => $this->menus->add(MenuName::Footer, 9, parent: $a), 'Menus can be at most 3 levels deep.');
    }

    public function testRemovingAnEntryKeepsTheEntriesUnderIt(): void
    {
        $a = $this->menus->add(MenuName::Main, 1);
        $b = $this->menus->add(MenuName::Main, 2);
        $c = $this->menus->add(MenuName::Main, 3, parent: $b);
        $d = $this->menus->add(MenuName::Main, 4);

        $id = (int) $b->id();
        $this->menus->remove($b, 3);

        self::assertSame([$a, $c, $d], array_map(static fn (MenuNode $node) => $node->item, $this->menus->tree(MenuName::Main)));
        self::assertSame([0, 1, 2], [$a->position(), $c->position(), $d->position()]);
        self::assertEquals(new MenuChanged(MenuName::Main, MenuChanged::REMOVED, $id, 2, null, 3), $this->last());
        self::assertNull($this->menus->find(999));
    }

    public function testChangesAnEntrysTextsAndWhoSeesIt(): void
    {
        $item = $this->menus->add(MenuName::Footer, null, 'Hours', '/hours');

        $this->menus->describe($item, 'Opening hours', ['pt-PT' => 'Horário'], true, 3);

        $this->database->entityManager->clear();
        $saved = $this->menus->find((int) $item->id());
        self::assertSame(['Opening hours', ['pt-PT' => 'Horário'], true], [$saved?->label(), $saved?->labels(), $saved?->isMembersOnly()]);
        self::assertEquals(new MenuChanged(MenuName::Footer, MenuChanged::CHANGED, (int) $item->id(), null, '/hours', 3), $this->last());
    }

    public function testForgetsTheEntriesOfPagesDeletedForGood(): void
    {
        $a = $this->menus->add(MenuName::Main, 1);
        $b = $this->menus->add(MenuName::Main, 2);
        $this->menus->add(MenuName::Main, 3, parent: $b);
        $this->menus->add(MenuName::Footer, 2);

        self::assertSame(0, $this->menus->forgetPages([]));
        self::assertSame(3, $this->menus->forgetPages([2, 3, 99], 4));

        self::assertSame([$a], array_map(static fn (MenuNode $node) => $node->item, $this->menus->tree(MenuName::Main)));
        self::assertSame([], $this->menus->tree(MenuName::Footer));
        self::assertSame(MenuChanged::REMOVED, $this->last()->change);
        self::assertSame(4, $this->last()->actorId);
    }

    private function assertRefused(callable $change, string $message): void
    {
        try {
            $change();
            self::fail('Expected: ' . $message);
        } catch (MenuException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return list<string> the page id or label of each entry, depth first
     */
    private function labels(MenuName $menu): array
    {
        $labels = [];
        $walk = static function (array $nodes) use (&$walk, &$labels): void {
            foreach ($nodes as $node) {
                \assert($node instanceof MenuNode);
                $labels[] = $node->item->label() ?? (string) $node->item->pageId();
                $walk($node->children);
            }
        };
        $walk($this->menus->tree($menu));

        return $labels;
    }

    private function last(): MenuChanged
    {
        $event = end($this->events->events);
        \assert($event instanceof MenuChanged);

        return $event;
    }
}
