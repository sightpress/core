<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Upload;

use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaLimits;

/**
 * The server values that the app limits need (`system:check --recommend`), with every file in one request, and with
 * chunked uploads, where one request carries a single 8 MB part.
 */
final readonly class ServerRecommendation
{
    private const int CHUNKED_UPLOAD = 16 * MediaLimits::MEGABYTE;

    private const int MINIMUM_MEMORY = 256 * MediaLimits::MEGABYTE;

    /** @var positive-int */
    public int $largestFile;

    /** @var positive-int */
    public int $memory;

    /**
     * @param non-empty-list<MediaLimits> $limits the limits of the sites to serve
     */
    public function __construct(array $limits)
    {
        $files = [];
        $pixels = [];
        foreach ($limits as $site) {
            $pixels[] = $site->imagePixels;
            foreach (MediaKind::cases() as $kind) {
                $files[] = $site->maxBytes($kind);
            }
        }
        $this->largestFile = max($files);
        $this->memory = max(self::MINIMUM_MEMORY, ServerLimitsCheck::memoryFor(max($pixels)));
    }

    /**
     * @return list<string>
     */
    public function lines(bool $chunked): array
    {
        $upload = $chunked ? self::CHUNKED_UPLOAD : $this->largestFile;
        $body = $upload + ServerLimits::REQUEST_OVERHEAD;
        $uploadSize = IniSize::shorthand($upload);
        $bodySize = IniSize::shorthand($body);
        $bodyBytes = (int) IniSize::bytes($bodySize);
        $memory = IniSize::shorthand($this->memory);

        return [
            '# .env',
            'UPLOAD_MAX_SIZE=' . $uploadSize,
            'INPUT_MAX_SIZE=8M',
            'INPUT_MAX_VARS=' . ServerLimitsCheck::RECOMMENDED_INPUT_VARS,
            'MAX_FILE_UPLOADS=20',
            'PHP_MEMORY_LIMIT=' . $memory,
            'UPLOAD_TIMEOUT=300',
            '# php.ini',
            'upload_max_filesize = ' . $uploadSize,
            'post_max_size = ' . $bodySize,
            'max_input_vars = ' . ServerLimitsCheck::RECOMMENDED_INPUT_VARS,
            'memory_limit = ' . $memory,
            'max_input_time = 300',
            '# nginx',
            'client_max_body_size ' . $bodySize . ';',
            'client_body_timeout 300s;',
            'fastcgi_read_timeout 300s;',
            '# Apache',
            'LimitRequestBody ' . $bodyBytes,
            '# Caddy',
            'request_body { max_size ' . $bodySize . 'iB }',
        ];
    }
}
