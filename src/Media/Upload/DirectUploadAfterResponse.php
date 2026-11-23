<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

use SightPress\Core\Http\Contract\RequestAnswered;

/**
 * Checks a file as soon as the browser says it has arrived, after the response has been sent, so a large file doesn't
 * hold a request open. If this stops, `schedule:run` picks the file up.
 */
final readonly class DirectUploadAfterResponse
{
    private const string COMPLETE = '#^/admin/media/direct/([0-9a-f]{32})/complete$#';

    public function __construct(private DirectUploads $uploads)
    {
    }

    public function __invoke(RequestAnswered $event): void
    {
        if ($event->status === 202 && $event->request->getMethod() === 'POST' && preg_match(self::COMPLETE, $event->request->getUri()->getPath(), $match) === 1) {
            set_time_limit(0);
            $this->uploads->validate($match[1]);
        }
    }
}
