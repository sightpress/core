<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Contract;

/**
 * Builds the addresses of media files: root-relative paths served by the site itself, for example
 * `/media/<key>/photo.jpg`, or absolute URLs on the site's CDN. Callers that need an absolute URL (Open Graph) prefix
 * root-relative ones with the site's base URL. On a members-only site with cloud delivery they are signed and expire,
 * so don't store them.
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

    /**
     * The file's root-relative address on the site itself, never on a CDN. Browsers only load a captions `<track>`
     * from the page's own origin unless the other host allows it with CORS.
     */
    public function siteUrl(MediaFile $file): string;

    /**
     * A short-lived address for someone already allowed to see this file, even if it isn't published. Never put it in
     * a page other people can read.
     */
    public function temporaryUrl(MediaFile $file, ?int $width = null): string;
}
