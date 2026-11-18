<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use SightPress\Core\Template\Message;

/**
 * Renders an {@see ErrorPage} as Problem Details JSON (RFC 9457) for clients that ask for JSON.
 */
final readonly class JsonErrorRenderer
{
    public const string CONTENT_TYPE = 'application/problem+json';

    public function render(ErrorPage $page, ?string $details = null): string
    {
        $problem = [
            'type' => 'about:blank',
            'title' => (string) Message::of($page->title),
            'status' => $page->status,
            'detail' => implode(' ', array_map(static fn (string|Message $text): string => (string) Message::of($text), $page->paragraphs)),
        ];
        if ($details !== null && $details !== '') {
            $problem['debug'] = $details;
        }

        return json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
