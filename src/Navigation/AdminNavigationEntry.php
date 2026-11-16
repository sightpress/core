<?php

declare(strict_types=1);

namespace SightPress\Core\Navigation;

/**
 * One entry of the admin navigation as a person sees it: its link, whether it is the current screen, and the
 * entries below it.
 */
final readonly class AdminNavigationEntry
{
    /**
     * @param string                    $label    en-GB text; templates translate it
     * @param list<AdminNavigationEntry> $children
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $url,
        public bool $current,
        public array $children = [],
    ) {
    }
}
