<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Someone deleted a file from the media library; its stored files are gone. The audit log records it, and modules
 * that refer to files by id can tidy up.
 */
final readonly class MediaDeleted
{
    /**
     * @param string $originalName the name it had on the uploader's computer
     * @param ?int   $deletedBy    who deleted it (null for the system)
     */
    public function __construct(
        public int $mediaId,
        public string $originalName,
        public ?int $deletedBy,
    ) {
    }
}
