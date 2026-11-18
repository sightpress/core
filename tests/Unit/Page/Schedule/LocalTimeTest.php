<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Schedule\LocalTime;
use SightPress\Core\Template\Message;

#[CoversClass(LocalTime::class)]
#[UsesNamespace('SightPress\Core')]
final class LocalTimeTest extends TestCase
{
    public function testTurnsATimeInThePersonsTimeZoneIntoUtcAndBack(): void
    {
        $field = new Message('Publish at');
        self::assertSame('2026-12-01 09:00:00 UTC', LocalTime::parse('2026-12-01T09:00', 'Europe/London', $field)?->format('Y-m-d H:i:s T'));
        self::assertSame('2026-07-01 08:00', LocalTime::parse('2026-07-01 09:00:00', 'Europe/London', $field)?->format('Y-m-d H:i'), 'summer time');
        self::assertSame('2026-12-31 20:30', LocalTime::parse(' 2027-01-01T09:30 ', 'Pacific/Auckland', $field)?->format('Y-m-d H:i'));
        self::assertNull(LocalTime::parse('  ', 'Europe/London', $field));

        // When the clocks go back, 01:30 happens twice: the second one (winter time) is the one meant.
        self::assertSame('2026-10-25 01:30', LocalTime::parse('2026-10-25T01:30', 'Europe/London', $field)?->format('Y-m-d H:i'));

        self::assertSame('2026-12-01T09:00', LocalTime::field(new \DateTimeImmutable('2026-12-01 09:00:00 UTC'), 'Europe/London'));
        self::assertSame('2026-07-01T10:00', LocalTime::field(new \DateTimeImmutable('2026-07-01 09:00:00 UTC'), 'Europe/London'));
        self::assertSame('', LocalTime::field(null, 'Europe/London'));
    }

    #[DataProvider('wrongTimes')]
    public function testRefusesWhatIsntADateAndTimeThere(string $value, string $message): void
    {
        try {
            LocalTime::parse($value, 'Europe/London', new Message('Publish at'));
            self::fail('Accepted ' . $value);
        } catch (PageException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wrongTimes(): iterable
    {
        $typed = 'Publish at: enter a date and time, for example 2026-12-01 09:00.';
        yield 'words' => ['tomorrow', $typed];
        yield 'no time' => ['2026-12-01', $typed];
        yield 'no such day' => ['2026-02-30T09:00', $typed];
        yield 'no such hour' => ['2026-12-01T25:00', $typed];
        yield 'clocks go forward' => ['2026-03-29T01:30', 'Publish at: that time doesn’t exist in your time zone, because the clocks go forward then. Choose another time.'];
    }
}
