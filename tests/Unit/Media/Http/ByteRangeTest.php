<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Http\ByteRange;
use SightPress\Core\Media\Http\UnsatisfiableRange;

#[CoversClass(ByteRange::class)]
#[CoversClass(UnsatisfiableRange::class)]
final class ByteRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function ranges(): iterable
    {
        yield 'from start to end' => ['bytes=0-99', 0, 99];
        yield 'open end' => ['bytes=900-', 900, 999];
        yield 'end past the file' => ['bytes=500-5000', 500, 999];
        yield 'last bytes' => ['bytes=-100', 900, 999];
        yield 'more last bytes than the file' => ['bytes=-5000', 0, 999];
        yield 'spaces and case' => [' Bytes = 10 - 19 ', 10, 19];
    }

    #[DataProvider('ranges')]
    public function testReadsASingleRange(string $header, int $start, int $end): void
    {
        $range = ByteRange::parse($header, 1000);

        self::assertNotNull($range);
        self::assertSame([$start, $end, $end - $start + 1], [$range->start, $range->end, $range->length()]);
        self::assertSame(sprintf('bytes %d-%d/1000', $start, $end), $range->contentRange(1000));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignored(): iterable
    {
        yield 'several ranges' => ['bytes=0-1,5-6'];
        yield 'another unit' => ['items=0-1'];
        yield 'no numbers' => ['bytes=-'];
        yield 'end before start' => ['bytes=9-3'];
        yield 'nonsense' => ['hello'];
    }

    #[DataProvider('ignored')]
    public function testAnythingButOneByteRangeMeansTheWholeFile(string $header): void
    {
        self::assertNull(ByteRange::parse($header, 1000));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unsatisfiable(): iterable
    {
        yield 'starts after the end' => ['bytes=1000-', 1000];
        yield 'zero last bytes' => ['bytes=-0', 1000];
        yield 'empty file' => ['bytes=-10', 0];
    }

    #[DataProvider('unsatisfiable')]
    public function testARangeOutsideTheFileCannotBeServed(string $header, int $size): void
    {
        $this->expectException(UnsatisfiableRange::class);

        ByteRange::parse($header, $size);
    }
}
