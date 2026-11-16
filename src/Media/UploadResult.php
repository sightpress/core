<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Entity\Media;

/**
 * The library entry for an upload, and whether the same file was there already (then nothing new was stored).
 */
final readonly class UploadResult
{
    public function __construct(
        public Media $media,
        public bool $duplicate,
    ) {
    }
}
