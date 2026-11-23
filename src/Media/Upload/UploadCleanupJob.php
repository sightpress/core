<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * Part of `schedule:run`: deletes abandoned uploads and old upload records.
 */
final readonly class UploadCleanupJob
{
    public function __invoke(ScheduleDue $event): void
    {
        $purged = Services::get($event->services, ChunkedUploads::class)->purgeExpired();
        if ($purged > 0) {
            $event->report(sprintf('Uploads: removed %d expired %s.', $purged, $purged === 1 ? 'session' : 'sessions'));
        }
    }
}
