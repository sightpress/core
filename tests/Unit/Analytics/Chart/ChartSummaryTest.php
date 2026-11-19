<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Chart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Chart\BarChart;
use SightPress\Core\Analytics\Chart\ChartSummary;
use SightPress\Core\Analytics\Chart\LineChart;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;

#[CoversClass(ChartSummary::class)]
#[CoversClass(LineChart::class)]
#[CoversClass(BarChart::class)]
#[UsesNamespace('SightPress\Core')]
final class ChartSummaryTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, list<int>|null, string}>
     */
    public static function lines(): iterable
    {
        yield 'up' => [[300, 1049, 0], [604, 600, 0], 'Visitors went up 12% compared with the previous 3 days, from 1,204 to 1,349. The busiest day was Wednesday 4 November, with 1,049 visitors. The quietest day was Thursday 5 November, with 0 visitors.'];
        yield 'down' => [[10, 10, 10], [20, 20, 20], 'Visitors went down 50% compared with the previous 3 days, from 60 to 30. The busiest day was Tuesday 3 November, with 10 visitors.'];
        yield 'flat' => [[1, 2, 3], [3, 2, 1], 'Visitors stayed the same as in the previous 3 days: 6. The busiest day was Thursday 5 November, with 3 visitors. The quietest day was Tuesday 3 November, with 1 visitor.'];
        yield 'none before' => [[0, 1, 0], [0, 0, 0], 'Visitors: 1 visitor in these 3 days, and none in the previous 3 days. The busiest day was Wednesday 4 November, with 1 visitor. The quietest day was Tuesday 3 November, with 0 visitors.'];
        yield 'down to nothing' => [[0, 0, 0], [1, 0, 0], 'Visitors went down 100% compared with the previous 3 days, from 1 to 0.'];
        yield 'no data' => [[0, 0, 0], [0, 0, 0], 'There is nothing to show for these 3 days.'];
        yield 'without the previous range' => [[5, 6, 7], null, 'Visitors: 18 visitors in these 3 days. The busiest day was Thursday 5 November, with 7 visitors. The quietest day was Tuesday 3 November, with 5 visitors.'];
    }

    /**
     * @param list<int>      $values
     * @param list<int>|null $previous
     */
    #[DataProvider('lines')]
    public function testSaysWhatTheLineShowsInWords(array $values, ?array $previous, string $summary): void
    {
        self::assertSame($summary, self::summary()->line(self::chart(['2026-11-03', '2026-11-04', '2026-11-05'], $values, $previous), 'en-GB'));
    }

    public function testOneDayAndNoDays(): void
    {
        self::assertSame('Visitors went up 13.5% compared with the previous day, from 37 to 42.', self::summary()->line(self::chart(['2026-11-13'], [42], [37]), 'en-GB'));
        self::assertSame('Visitors: 4 visitors on this day.', self::summary()->line(self::chart(['2026-11-13'], [4]), 'en-GB'));
        self::assertSame('There is nothing to show for this day.', self::summary()->line(self::chart(['2026-11-13'], [0]), 'en-GB'));
        self::assertSame('There is nothing to show for these dates.', self::summary()->line(self::chart([], []), 'en-GB'));
    }

    public function testBarsNameTheLargest(): void
    {
        $bar = self::bars(...);

        self::assertSame('The most: Phone, with 600 visitors (60% of all).', self::summary()->bar($bar([['label' => 'Computer', 'value' => 400], ['label' => 'Phone', 'value' => 600]]), 'en-GB'));
        self::assertSame('All of them: Computer, with 1 visitor.', self::summary()->bar($bar([['label' => 'Computer', 'value' => 1]]), 'en-GB'));
        self::assertSame('There is nothing to show yet.', self::summary()->bar($bar([['label' => 'Computer', 'value' => 0]]), 'en-GB'));
        self::assertSame('There is nothing to show yet.', self::summary()->bar($bar([]), 'en-GB'));
    }

    public function testNumbersAndDaysAreWrittenTheViewersWay(): void
    {
        $summary = new ChartSummary(Translations::fromDirectories([dirname(__DIR__, 4) . '/resources/translations']));

        $text = $summary->line(self::chart(['2026-11-03', '2026-11-04'], [1000, 2500], [1000, 1000]), 'pt-PT');
        self::assertStringContainsString("2000 para 3500", (string) preg_replace('/(?<=\d)\h(?=\d)/u', '', $text));
        self::assertStringContainsString('quarta-feira, 4 de novembro', $text);
    }

    /**
     * @param list<array{label: string, value: int}> $bars
     */
    private static function bars(array $bars): BarChart
    {
        return new BarChart('devices', new Message('Devices'), new Message('{count, plural, one {# visitor} other {# visitors}}'), $bars);
    }

    private static function summary(): ChartSummary
    {
        return new ChartSummary(Translations::fromDirectories([]));
    }

    /**
     * @param list<string>   $days
     * @param list<int>      $values
     * @param list<int>|null $previous
     */
    private static function chart(array $days, array $values, ?array $previous = null): LineChart
    {
        return new LineChart('visits', new Message('Visitors'), new Message('{count, plural, one {# visitor} other {# visitors}}'), $days, $values, $previous);
    }
}
