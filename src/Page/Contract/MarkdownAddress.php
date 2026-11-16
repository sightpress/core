<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * Where the Markdown version of a page is: its address followed by `.md` (`/about/team.md`), and `index.md` for a
 * home page (`/index.md`, `/pt-pt/index.md`).
 */
final class MarkdownAddress
{
    public static function of(string $url, bool $home): string
    {
        return $home ? rtrim($url, '/') . '/index.md' : $url . '.md';
    }
}
