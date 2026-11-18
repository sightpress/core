<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * What {@see StarterContent} wrote: the titles of the pages (in the first language), the languages, and the languages
 * that had no starter pages of their own and got British English (or the closest language) instead.
 */
final readonly class StarterReport
{
    /**
     * @param list<string> $titles
     * @param list<string> $locales
     * @param list<string> $fallbacks
     */
    public function __construct(
        public array $titles,
        public array $locales,
        public array $fallbacks = [],
    ) {
    }
}
