<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Module\Block\BlockContext;

/**
 * A block context that isn't core's site context, like one another module might pass: media ids map to
 * `https://cdn.example/<id>` unless listed as missing.
 */
final readonly class PlainBlockContext implements BlockContext
{
    /**
     * @param list<string> $missing media ids that aren't found
     */
    public function __construct(private array $missing = [])
    {
    }

    public function locale(): string
    {
        return 'en-GB';
    }

    public function absoluteUrl(string $path): string
    {
        return 'https://plain.example/' . ltrim($path, '/');
    }

    public function mediaUrl(string $mediaId, ?string $variant = null): ?string
    {
        return in_array($mediaId, $this->missing, true) ? null : 'https://cdn.example/' . $mediaId . ($variant === null ? '' : '/' . $variant);
    }
}
