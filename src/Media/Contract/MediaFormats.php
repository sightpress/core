<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Names of the file types the media library accepts, for people: "PDF", "Word", "WebVTT captions".
 */
interface MediaFormats
{
    /**
     * @return non-empty-string|null null when the type isn't one the library accepts
     */
    public function label(string $mimeType): ?string;
}
