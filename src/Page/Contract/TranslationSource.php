<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * A page's texts in one language, to translate (A-6): title, summary, search title and description, and blocks.
 */
final readonly class TranslationSource
{
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks
     */
    public function __construct(
        public string $title,
        public ?string $summary,
        public ?string $seoTitle,
        public ?string $description,
        public array $blocks,
    ) {
    }
}
