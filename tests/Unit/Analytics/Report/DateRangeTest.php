<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Report;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Report\DateRange;

#[CoversClass(DateRange::class)]
final class DateRangeTest extends TestCase
{
    public function testHasItsDaysAndThePreviousRangeOfTheSameLength(): void
    {
        $range = new DateRange('2026-10-30', '2026-11-02');

        self::assertSame(4, $range->days());
        self::assertSame(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], $range->each());
        self::assertSame(['2026-10-26', '2026-10-29'], [$range->previous()->from, $range->previous()->to]);
        self::assertTrue($range->contains('2026-11-02'));
        self::assertFalse($range->contains('2026-11-03'));

        $week = DateRange::last(7, '2026-03-31');
        self::assertSame(['2026-03-25', '2026-03-31', 7], [$week->from, $week->to, $week->days()]);
        self::assertSame(['2026-03-31', '2026-03-31'], [DateRange::last(0, '2026-03-31')->from, DateRange::last(0, '2026-03-31')->to]);
        self::assertSame(['2026-02-28', '2026-02-28'], [new DateRange('2026-03-01', '2026-03-01')->previous()->from, new DateRange('2026-03-01', '2026-03-01')->previous()->to]);
    }

    public function testRefusesDatesThatAreNotDaysOrInTheWrongOrderOrTooLong(): void
    {
        foreach ([['2026-11-31', '2026-12-01'], ['14/11/2026', '2026-12-01'], ['2026-11-02', '2026-11-01'], ['2024-01-01', '2026-12-31']] as [$from, $to]) {
            try {
                new DateRange($from, $to);
                self::fail(sprintf('accepted %s to %s', $from, $to));
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        self::assertFalse(DateRange::isDay('2026-02-29'));
        self::assertTrue(DateRange::isDay('2028-02-29'));
    }
}
