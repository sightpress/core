<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use SightPress\Core\Template\Message;

/**
 * What an error page says: the status, a heading that names the problem in plain words, and short paragraphs that
 * say what to do next. All text is plain en-GB (translated and escaped when rendered).
 */
final readonly class ErrorPage
{
    /**
     * @param int<400, 599>          $status
     * @param non-empty-string|Message       $title      used for the `<title>` and the `<h1>` (shown translated)
     * @param list<non-empty-string|Message> $paragraphs
     * @param array<string, string>  $headers    extra response headers, for example `Allow` or `Retry-After`
     * @param bool                   $homeLink   whether to link to the home page
     * @param array<string, string|Message> $links more links after the home page, path => link text
     */
    public function __construct(
        public int $status,
        public string|Message $title,
        public array $paragraphs,
        public array $headers = [],
        public bool $homeLink = true,
        public array $links = [],
    ) {
    }
}
