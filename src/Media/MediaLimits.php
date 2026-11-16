<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaKind;

/**
 * How large an upload may be. Pictures also have a pixel limit, because they are decoded in memory to be
 * re-encoded (about 4 bytes per pixel): the default of 25 million pixels needs a PHP `memory_limit` of 256M.
 */
final readonly class MediaLimits
{
    public const int MEGABYTE = 1024 * 1024;

    /**
     * @param positive-int $imageBytes
     * @param positive-int $videoBytes
     * @param positive-int $audioBytes
     * @param positive-int $documentBytes
     * @param positive-int $imagePixels width × height
     */
    public function __construct(
        public int $imageBytes = 20 * self::MEGABYTE,
        public int $videoBytes = 1024 * self::MEGABYTE,
        public int $audioBytes = 200 * self::MEGABYTE,
        public int $documentBytes = 50 * self::MEGABYTE,
        public int $imagePixels = 25_000_000,
    ) {
    }

    /**
     * @return positive-int
     */
    public function maxBytes(MediaKind $kind): int
    {
        return match ($kind) {
            MediaKind::Image => $this->imageBytes,
            MediaKind::Video => $this->videoBytes,
            MediaKind::Audio => $this->audioBytes,
            MediaKind::Document => $this->documentBytes,
        };
    }

    /**
     * A size for people: "900 bytes", "12 KB", "2.3 MB", "1.5 GB" (1 KB = 1024 bytes).
     */
    public static function describe(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d bytes', $bytes);
        }
        $value = $bytes / 1024;
        $unit = 'KB';
        foreach (['MB', 'GB'] as $next) {
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit = $next;
        }
        $text = $value < 10 ? rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') : (string) (int) round($value);

        return $text . ' ' . $unit;
    }
}
