<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * What {@see ThemeChecker} found: problems that stop the theme from being applied, warnings that don't, and how many
 * colour pairs were checked.
 */
final readonly class ThemeCheck
{
    /**
     * @param string       $name     the theme's name, or its folder when it has none
     * @param list<string>     $problems what to fix before the theme can be applied
     * @param list<string>     $warnings what to fix soon
     * @param int<0, max>      $checks   colour pairs checked, counted once per mode
     */
    public function __construct(
        public string $name,
        public array $problems,
        public array $warnings = [],
        public int $checks = 0,
    ) {
    }

    public function passes(): bool
    {
        return $this->problems === [];
    }
}
