<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

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
            'title' => $page->title,
            'status' => $page->status,
            'detail' => implode(' ', $page->paragraphs),
        ];
        if ($details !== null && $details !== '') {
            $problem['debug'] = $details;
        }

        return json_encode($problem, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
