<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * One static file of the theme, ready to send.
 */
final readonly class ThemeAsset
{
    public function __construct(
        public string $contents,
        public string $contentType,
    ) {
    }

    /**
     * A strong validator for `ETag` / `If-None-Match`.
     */
    public function etag(): string
    {
        return '"' . hash('xxh128', $this->contents) . '"';
    }
}
