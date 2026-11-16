<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaLimits;

#[CoversClass(MediaLimits::class)]
final class MediaLimitsTest extends TestCase
{
    public function testHasALimitPerKind(): void
    {
        $limits = new MediaLimits(1, 2, 3, 4);

        self::assertSame([1, 2, 3, 4], array_map($limits->maxBytes(...), [MediaKind::Image, MediaKind::Video, MediaKind::Audio, MediaKind::Document]));
        self::assertSame(20 * MediaLimits::MEGABYTE, new MediaLimits()->maxBytes(MediaKind::Image));
        self::assertSame(25_000_000, new MediaLimits()->imagePixels);
    }

    public function testDescribesSizesForPeople(): void
    {
        $sizes = [900 => '900 bytes', 1024 => '1 KB', 12_800 => '13 KB', 2_411_725 => '2.3 MB', 20 * MediaLimits::MEGABYTE => '20 MB', 1_610_612_736 => '1.5 GB', 5 * 1024 ** 4 => '5120 GB'];

        foreach ($sizes as $bytes => $text) {
            self::assertSame($text, MediaLimits::describe($bytes));
        }
    }
}
