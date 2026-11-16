<?php

declare(strict_types=1);

namespace SightPress\Core\Menu;

use SightPress\Core\Menu\Entity\MenuItem;

/**
 * A menu entry with the entries under it.
 */
final readonly class MenuNode
{
    /**
     * @param list<MenuNode> $children
     */
    public function __construct(
        public MenuItem $item,
        public array $children,
    ) {
    }

    /**
     * Ids of the pages this entry and the ones under it link to.
     *
     * @param list<MenuNode> $nodes
     *
     * @return list<int>
     */
    public static function pageIds(array $nodes): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            if ($node->item->pageId() !== null) {
                $ids[] = $node->item->pageId();
            }
            $ids = [...$ids, ...self::pageIds($node->children)];
        }

        return $ids;
    }
}
