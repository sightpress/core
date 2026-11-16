<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

/**
 * The colours of the generated icon, share image and wordmark, and the browser's `theme-color`: white on a deep blue
 * (8.6:1), well above the 4.5:1 that text needs.
 */
final class BrandColours
{
    public const string BACKGROUND = '#1f4e79';

    public const string FOREGROUND = '#ffffff';

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    public static function rgb(string $hex): array
    {
        $channel = static fn (int $offset): int => min(255, max(0, (int) hexdec(substr($hex, $offset, 2))));

        return [$channel(1), $channel(3), $channel(5)];
    }
}
