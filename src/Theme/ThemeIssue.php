<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * One reason a theme can't be used, in a sentence that says what to change.
 */
final readonly class ThemeIssue
{
    /**
     * @param non-empty-string $message
     */
    public function __construct(
        public string $message,
        public ?ContrastPair $pair = null,
        public ?ColorMode $mode = null,
        public ?float $ratio = null,
    ) {
    }
}
