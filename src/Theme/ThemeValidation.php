<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * What the {@see ThemeValidator} found: the issues (none means the theme passes) and how many checks it ran.
 */
final readonly class ThemeValidation
{
    /**
     * @param list<ThemeIssue> $issues
     * @param int<0, max>      $checks colour pairs checked, counted once per mode
     */
    public function __construct(
        public Theme $theme,
        public array $issues,
        public int $checks,
    ) {
    }

    public function passes(): bool
    {
        return $this->issues === [];
    }
}
