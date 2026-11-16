<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use DateTimeImmutable;
use DateTimeZone;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Page\Contract\MarkdownPages;

/**
 * Builds the Markdown document of a page from its blocks' Markdown (every block type gives its own; forms give
 * nothing). Links and pictures in it are absolute.
 */
final readonly class PageMarkdown implements MarkdownPages
{
    public function __construct(
        private BlockContexts $contexts,
        private BlockRenderer $renderer,
    ) {
    }

    public function document(string $locale, string $title, ?string $summary, array $blocks, string $canonicalUrl, DateTimeImmutable $updatedAt, string $baseUrl): string
    {
        $parts = ['# ' . self::line($title)];
        if ($summary !== null && trim($summary) !== '') {
            $parts[] = '> ' . self::line($summary);
        }
        $body = trim($this->renderer->markdown($blocks, $this->contexts->create($locale, rtrim($baseUrl, '/'))));
        if ($body !== '') {
            $parts[] = $body;
        }
        $parts[] = "---\n\nSource: <" . $canonicalUrl . ">  \nLast updated: " . $updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

        return implode("\n\n", $parts) . "\n";
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
