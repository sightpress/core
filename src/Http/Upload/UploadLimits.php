<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use SightPress\Core\Template\Message;

/**
 * The largest files people can upload to this site, after its own limits and the server's: for other modules' upload
 * forms and for the site's settings screen.
 */
interface UploadLimits
{
    /**
     * "Largest file you can upload: pictures 20 MB, documents 50 MB, audio files 64 MB and videos 64 MB."
     */
    public function summary(): Message;

    /**
     * The largest picture a form with its own limit accepts: the smallest of that limit, the site's limit for
     * pictures and what one request can carry.
     *
     * @param positive-int $ownLimit
     *
     * @return positive-int
     */
    public function pictureLimit(int $ownLimit): int;

    /**
     * A size for people: "900 bytes", "2.3 MB", "1.5 GB".
     */
    public function describe(int $bytes): string;
}
