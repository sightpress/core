<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use DateTimeImmutable;

/**
 * Pages as Markdown documents, for readers that prefer text (AI search engines, `/{path}.md`, `llms-full.txt`):
 * the title as the heading, the summary as a quote, the blocks, then where the page is and when it last changed.
 */
interface MarkdownPages
{
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     * @param string                                                              $canonicalUrl the page's address
     *                                                                                          (absolute)
     * @param string                                                              $baseUrl      scheme and host
     */
    public function document(string $locale, string $title, ?string $summary, array $blocks, string $canonicalUrl, DateTimeImmutable $updatedAt, string $baseUrl): string;
}
