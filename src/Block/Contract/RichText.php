<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Core\Block\RichText\RichTextSanitizer;

/**
 * Rich text as a rich text block keeps it, for modules that take HTML from elsewhere (the AI assistant): only the
 * tags and attributes the block allows, and safe link addresses.
 */
final readonly class RichText
{
    private RichTextSanitizer $sanitizer;

    public function __construct()
    {
        $this->sanitizer = new RichTextSanitizer();
    }

    public function clean(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
