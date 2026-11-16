<?php

declare(strict_types=1);

namespace SightPress\Core\Menu\Contract;

/**
 * The menus of a site: the main menu at the top of every page and the footer menu at the bottom.
 */
enum MenuName: string
{
    case Main = 'main';
    case Footer = 'footer';

    /**
     * The menu's name for people, in en-GB.
     */
    public function label(): string
    {
        return match ($this) {
            self::Main => 'Main menu',
            self::Footer => 'Footer menu',
        };
    }
}
