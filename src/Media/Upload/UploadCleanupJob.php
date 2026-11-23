<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * Part of `schedule:run`: checks files that arrived in the bucket and weren't checked yet, and deletes abandoned
 * uploads and old upload records.
 */
final readonly class UploadCleanupJob
{
    public function __invoke(ScheduleDue $event): void
    {
        $direct = Services::get($event->services, DirectUploads::class);
        $direct->validateWaiting();
        $purged = Services::get($event->services, ChunkedUploads::class)->purgeExpired() + $direct->purgeExpired();
        if ($purged > 0) {
            $event->report(sprintf('Uploads: removed %d expired %s.', $purged, $purged === 1 ? 'session' : 'sessions'));
        }
    }
}
