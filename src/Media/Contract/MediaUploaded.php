<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Dispatched after a new file was added to a site's media library (not when an upload matched a file that was
 * already there).
 */
final readonly class MediaUploaded
{
    public function __construct(
        public int $mediaId,
        public string $key,
        public MediaKind $kind,
        public ?int $uploadedBy,
    ) {
    }
}
