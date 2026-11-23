<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use DateTimeImmutable;

/**
 * One file on its way to the media library, sent in parts.
 */
final readonly class UploadSession
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $fileName,
        public int $totalBytes,
        public int $receivedBytes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $completedAt = null,
        public ?int $mediaId = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->completedAt !== null;
    }
}
