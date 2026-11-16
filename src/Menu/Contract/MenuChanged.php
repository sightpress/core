<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Contract;

/**
 * A menu was changed: an entry was added, removed or moved. For the audit log.
 */
final readonly class MenuChanged
{
    public const string ADDED = 'added';
    public const string REMOVED = 'removed';
    public const string MOVED_UP = 'moved_up';
    public const string MOVED_DOWN = 'moved_down';
    public const string INDENTED = 'indented';
    public const string OUTDENTED = 'outdented';

    /**
     * @param string   $change  one of the constants
     * @param ?int     $pageId  the page the entry links to, or null for a link
     * @param ?string  $url     the entry's address when it is a link
     * @param ?int     $actorId who made the change, when a person did
     */
    public function __construct(
        public MenuName $menu,
        public string $change,
        public int $itemId,
        public ?int $pageId,
        public ?string $url,
        public ?int $actorId,
    ) {
    }
}
