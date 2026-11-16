<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

/**
 * A picture after re-encoding: the full-size file and its smaller copies, without any metadata.
 */
final readonly class ProcessedImage
{
    /**
     * @param array<int, string> $variants width => encoded smaller copy
     */
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
        public array $variants,
    ) {
    }
}
