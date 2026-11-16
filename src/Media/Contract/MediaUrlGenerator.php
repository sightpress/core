<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Builds the addresses of media files. In origin mode (this version) they are root-relative paths served by the
 * site itself, for example `/media/<key>/photo.jpg`; a CDN mode (later) returns absolute URLs. Callers that need
 * an absolute URL (Open Graph) prefix root-relative ones with the site's base URL.
 *
 * Addresses contain the file's key, which names one content for good, so they can be cached for good too.
 */
interface MediaUrlGenerator
{
    /**
     * The address of the file, or of its smaller copy `$width` pixels wide. A width the file has no copy for gives
     * the original.
     */
    public function url(MediaFile $file, ?int $width = null): string;

    /**
     * A `srcset` value listing the smaller copies and the original with their widths, for pictures; an empty string
     * for other files.
     */
    public function srcset(MediaFile $file): string;
}
