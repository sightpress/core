<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaKind;

/**
 * One file type the media library accepts.
 */
final readonly class MediaFormat
{
    /**
     * @param non-empty-string $mimeType  the type finfo detects and the type files are served with
     * @param non-empty-string $extension lower case, without the dot
     * @param non-empty-string $label     how people know the type, for example "PDF" or "Word document"
     */
    public function __construct(
        public string $mimeType,
        public MediaKind $kind,
        public string $extension,
        public string $label,
    ) {
    }
}
