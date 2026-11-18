<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Template\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Core\Menu\Contract\MenuChanged;
use SightPress\Core\Menu\Contract\MenuName;
use SightPress\Core\Menu\Entity\MenuItem;

/**
 * Reads and changes a site's menus. Each change saves at once and dispatches {@see MenuChanged}; positions among
 * siblings are always 0, 1, 2 …
 */
final readonly class MenuEditor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $events,
    ) {
    }

    public function find(int $id): ?MenuItem
    {
        return $this->entityManager->find(MenuItem::class, $id);
    }

    /**
     * Every entry of a menu, in one query, ordered by position.
     *
     * @return list<MenuItem>
     */
    public function items(MenuName $menu): array
    {
        /** @var list<MenuItem> */
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(MenuItem::class, 'i')
            ->where('i.menu = :menu')
            ->setParameter('menu', $menu)
            ->orderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The menu as a tree.
     *
     * @return list<MenuNode>
     */
    public function tree(MenuName $menu): array
    {
        $children = [];
        foreach ($this->items($menu) as $item) {
            $children[$item->parent()?->id() ?? 0][] = $item;
        }

        return self::nodes($children, 0);
    }

    /**
     * Adds an entry at the end of the menu's top level: a page, or a link with a label.
     */
    public function add(MenuName $menu, ?int $pageId, ?string $label = null, ?string $url = null, ?int $actorId = null, ?MenuItem $parent = null): MenuItem
    {
        if ($parent !== null && ($parent->menu() !== $menu || self::depth($parent) + 1 >= MenuItem::MAX_DEPTH)) {
            throw new MenuException(new Message('Menus can be at most {max} levels deep.', ['max' => (string) MenuItem::MAX_DEPTH]));
        }
        $item = new MenuItem($menu, $parent, $pageId, $label, $url);
        $item->place($parent, count($this->siblings($menu, $parent)));
        $this->entityManager->persist($item);
        $this->entityManager->flush();
        $this->changed($item, MenuChanged::ADDED, $actorId);

        return $item;
    }

    /**
     * Removes an entry; the entries under it take its place.
     */
    public function remove(MenuItem $item, ?int $actorId = null): void
    {
        $siblings = $this->siblings($item->menu(), $item->parent());
        $index = (int) array_search($item, $siblings, true);
        $children = $this->siblings($item->menu(), $item);
        array_splice($siblings, $index, 1, $children);
        $this->renumber($siblings, $item->parent());
        $this->changed($item, MenuChanged::REMOVED, $actorId);
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    public function moveUp(MenuItem $item, ?int $actorId = null): void
    {
        $this->swap($item, -1, MenuChanged::MOVED_UP, $actorId);
    }

    public function moveDown(MenuItem $item, ?int $actorId = null): void
    {
        $this->swap($item, 1, MenuChanged::MOVED_DOWN, $actorId);
    }

    /**
     * Puts the entry under the one before it, at the end.
     */
    public function indent(MenuItem $item, ?int $actorId = null): void
    {
        $siblings = $this->siblings($item->menu(), $item->parent());
        $index = (int) array_search($item, $siblings, true);
        if (!$this->canIndent($item)) {
            throw new MenuException($index === 0
                ? 'The first entry at a level can\'t be moved in: there is no entry before it to go under.'
                : new Message('Menus can be at most {max} levels deep.', ['max' => (string) MenuItem::MAX_DEPTH]));
        }
        $oldParent = $item->parent();
        $parent = $siblings[$index - 1];
        $item->place($parent, count($this->siblings($item->menu(), $parent)));
        array_splice($siblings, $index, 1);
        $this->renumber($siblings, $oldParent);
        $this->entityManager->flush();
        $this->changed($item, MenuChanged::INDENTED, $actorId);
    }

    /**
     * Takes the entry out from under its parent, right after the parent.
     */
    public function outdent(MenuItem $item, ?int $actorId = null): void
    {
        $parent = $item->parent();
        if ($parent === null) {
            throw new MenuException('This entry is already at the top level.');
        }
        $old = $this->siblings($item->menu(), $parent);
        array_splice($old, (int) array_search($item, $old, true), 1);
        $this->renumber($old, $parent);
        $siblings = $this->siblings($item->menu(), $parent->parent());
        array_splice($siblings, (int) array_search($parent, $siblings, true) + 1, 0, [$item]);
        $this->renumber($siblings, $parent->parent());
        $this->entityManager->flush();
        $this->changed($item, MenuChanged::OUTDENTED, $actorId);
    }

    private function canMoveUp(MenuItem $item): bool
    {
        return $this->siblings($item->menu(), $item->parent())[0] !== $item;
    }

    private function canIndent(MenuItem $item): bool
    {
        return $this->canMoveUp($item) && self::depth($item) + 1 + $this->height($item) <= MenuItem::MAX_DEPTH;
    }

    /**
     * 0 at the top level.
     */
    private static function depth(MenuItem $item): int
    {
        $depth = 0;
        for ($parent = $item->parent(); $parent !== null; $parent = $parent->parent()) {
            ++$depth;
        }

        return $depth;
    }

    /**
     * How many levels the entry takes with the entries under it: 1 without any.
     */
    private function height(MenuItem $item): int
    {
        $height = 0;
        foreach ($this->siblings($item->menu(), $item) as $child) {
            $height = max($height, $this->height($child));
        }

        return $height + 1;
    }

    /**
     * @param array<int, list<MenuItem>> $children
     *
     * @return list<MenuNode>
     */
    private static function nodes(array $children, int $parent): array
    {
        $nodes = [];
        foreach ($children[$parent] ?? [] as $item) {
            $nodes[] = new MenuNode($item, self::nodes($children, (int) $item->id()));
        }

        return $nodes;
    }

    private function swap(MenuItem $item, int $direction, string $change, ?int $actorId): void
    {
        $siblings = $this->siblings($item->menu(), $item->parent());
        $index = (int) array_search($item, $siblings, true);
        $other = $index + $direction;
        if (!isset($siblings[$other])) {
            throw new MenuException($direction < 0 ? 'This entry is already the first at its level.' : 'This entry is already the last at its level.');
        }
        [$siblings[$index], $siblings[$other]] = [$siblings[$other], $siblings[$index]];
        $this->renumber(array_values($siblings), $item->parent());
        $this->entityManager->flush();
        $this->changed($item, $change, $actorId);
    }

    /**
     * The entries under a parent (or at the top), in their order, including changes not saved yet.
     *
     * @return list<MenuItem>
     */
    private function siblings(MenuName $menu, ?MenuItem $parent): array
    {
        $siblings = array_values(array_filter(
            $this->items($menu),
            static fn (MenuItem $item): bool => $item->parent() === $parent,
        ));
        usort($siblings, static fn (MenuItem $a, MenuItem $b): int => [$a->position(), $a->id()] <=> [$b->position(), $b->id()]);

        return $siblings;
    }

    /**
     * @param list<MenuItem> $items
     */
    private function renumber(array $items, ?MenuItem $parent): void
    {
        foreach ($items as $position => $item) {
            $item->place($parent, $position);
        }
    }

    private function changed(MenuItem $item, string $change, ?int $actorId): void
    {
        $this->events->dispatch(new MenuChanged($item->menu(), $change, (int) $item->id(), $item->pageId(), $item->url(), $actorId));
    }
}
