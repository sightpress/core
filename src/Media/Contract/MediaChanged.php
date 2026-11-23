<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Someone changed a file in the media library: its alt text, caption, transcript or whether it is published. The
 * audit log records it.
 */
final readonly class MediaChanged
{
    /**
     * @param ?int         $changedBy who changed it (null for the system)
     * @param list<string> $fields    what changed: `alt`, `caption`, `transcript`, `captions`, `published`
     */
    public function __construct(
        public int $mediaId,
        public ?int $changedBy,
        public array $fields,
    ) {
    }
}
