<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

/**
 * What an error page says: the status, a heading that names the problem in plain words, and short paragraphs that
 * say what to do next. All text is plain (it is escaped when rendered).
 */
final readonly class ErrorPage
{
    /**
     * @param int<400, 599>          $status
     * @param non-empty-string       $title      used for the `<title>` and the `<h1>`
     * @param list<non-empty-string> $paragraphs
     * @param array<string, string>  $headers    extra response headers, for example `Allow` or `Retry-After`
     * @param bool                   $homeLink   whether to link to the home page
     */
    public function __construct(
        public int $status,
        public string $title,
        public array $paragraphs,
        public array $headers = [],
        public bool $homeLink = true,
    ) {
    }
}
