<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Chart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Chart\BarChart;
use SightPress\Core\Analytics\Chart\ChartRenderer;
use SightPress\Core\Analytics\Chart\ChartSummary;
use SightPress\Core\Analytics\Chart\ChartView;
use SightPress\Core\Analytics\Chart\LineChart;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;

#[CoversClass(ChartView::class)]
#[UsesNamespace('SightPress\Core')]
final class ChartViewTest extends TestCase
{
    public function testALineChartComesWithItsSummaryKeyAndTable(): void
    {
        $view = self::view()->line(
            new LineChart('visits', new Message('Visitors'), new Message('{count, plural, one {# visitor} other {# visitors}}'), ['2026-11-12', '2026-11-13'], [4, 1200], [2, 3]),
            'en-GB',
            new Message('Last 2 days'),
            new Message('The 2 days before'),
        );

        self::assertSame('visits', $view['id']);
        self::assertFalse($view['empty']);
        self::assertStringStartsWith('<svg', $view['svg']);
        self::assertStringStartsWith('Visitors went up', $view['summary']);
        self::assertSame([['series' => 'current', 'label' => 'Last 2 days: solid line with round markers'], ['series' => 'previous', 'label' => 'The 2 days before: dashed line with square markers']], $view['key']);
        self::assertSame(['Show the data for each day', 'Visitors for each day', ['Day', 'Visitors', 'The 2 days before']], [$view['disclosure'], $view['caption'], $view['columns']]);
        self::assertSame([['Thursday 12 November', '4', '2 on Tuesday 10 November'], ['Friday 13 November', '1,200', '3 on Wednesday 11 November']], $view['rows']);

        $alone = self::view()->line(new LineChart('alone', new Message('Visitors'), new Message('{count}'), ['2026-11-13'], [5]), 'en-GB', new Message('Today'), new Message('Yesterday'));
        self::assertCount(1, $alone['key']);
        self::assertSame([['Friday 13 November', '5']], $alone['rows']);
    }

    public function testABarChartIsATableWithDecorativeBars(): void
    {
        $view = self::view()->bar(new BarChart('devices', new Message('Devices'), new Message('{count}'), [['label' => 'Computer', 'value' => 1500], ['label' => 'Phone', 'value' => 750]]), 'en-GB', new Message('Device'));

        self::assertSame(['Devices', ['Device', 'Number']], [$view['caption'], $view['columns']]);
        self::assertSame(['Computer', '1,500'], [$view['rows'][0]['label'], $view['rows'][0]['value']]);
        self::assertStringContainsString('aria-hidden="true"', $view['rows'][1]['bar']);
        self::assertStringContainsString('width="50%"', $view['rows'][1]['bar']);

        $empty = self::view()->bar(new BarChart('none', new Message('Devices'), new Message('{count}'), []), 'en-GB', new Message('Device'));
        self::assertSame([true, '', [], 'There is nothing to show yet.'], [$empty['empty'], $empty['svg'], $empty['rows'], $empty['summary']]);
    }

    private static function view(): ChartView
    {
        $translations = Translations::fromDirectories([]);

        return new ChartView(new ChartRenderer($translations), new ChartSummary($translations), $translations);
    }
}
