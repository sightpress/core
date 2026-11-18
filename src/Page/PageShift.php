<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

/**
 * One step of a page in the tree, for buttons: up or down among its siblings, in (under the page before it, as its
 * last subpage) or out (next to the page it is under).
 */
enum PageShift: string
{
    case Up = 'up';
    case Down = 'down';
    case In = 'in';
    case Out = 'out';

    /**
     * For buttons: "Move up".
     */
    public function label(): string
    {
        return match ($this) {
            self::Up => 'Move up',
            self::Down => 'Move down',
            self::In => 'Move under the page before it',
            self::Out => 'Move out to the level above',
        };
    }
}
