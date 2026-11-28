<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Privacy;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Privacy\ExportedDate;
use SightPress\Core\Infrastructure\Privacy\Row;

#[CoversClass(Row::class)]
#[CoversClass(ExportedDate::class)]
final class RowTest extends TestCase
{
    public function testReadsNumbersTextAndDates(): void
    {
        self::assertSame(7, Row::int('7'));
        self::assertSame(0, Row::int(null));
        self::assertSame('7', Row::text(7));
        self::assertNull(Row::text([]));
        self::assertSame('2026-11-28T12:00:00Z', Row::date('2026-11-28 12:00:00'));
        self::assertNull(Row::date(null));
        self::assertNull(ExportedDate::of(''));
        self::assertSame('not a date', ExportedDate::of('not a date'));
        self::assertSame('2026-11-28T11:00:00Z', ExportedDate::of(new DateTimeImmutable('2026-11-28 12:00:00+01:00')));
    }
}
