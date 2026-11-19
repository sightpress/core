<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * The saved text of a page in one language, for a person to share with a helper such as the AI assistant: its
 * title, its summary and its blocks as Markdown.
 */
final readonly class PageText
{
    public function __construct(
        public int $pageId,
        public string $locale,
        public string $title,
        public string $summary,
        public string $text,
    ) {
    }
}
