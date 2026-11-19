<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics\Chart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\Chart\BarChart;
use SightPress\Core\Analytics\Chart\ChartFormat;
use SightPress\Core\Analytics\Chart\ChartRenderer;
use SightPress\Core\Analytics\Chart\LineChart;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;

/**
 * Golden SVG files: run with `SIGHTPRESS_UPDATE_GOLDEN=1` to write them again after a deliberate change, then look at
 * the chart style guide (`/charts` on the component gallery) before committing them.
 */
#[CoversClass(ChartRenderer::class)]
#[CoversClass(LineChart::class)]
#[CoversClass(BarChart::class)]
#[CoversClass(ChartFormat::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class ChartRendererTest extends TestCase
{
    /**
     * @return iterable<string, array{string, LineChart|BarChart}>
     */
    public static function charts(): iterable
    {
        $title = new Message('Visitors');
        $amount = new Message('{count, plural, one {# visitor} other {# visitors}}');
        yield 'a week with the week before' => ['line-week', new LineChart('week', $title, $amount, self::days('2026-11-07', 7), [12, 30, 25, 0, 41, 98, 64], [10, 22, 31, 18, 40, 52, 60])];
        yield 'a single day' => ['line-single', new LineChart('day', $title, $amount, ['2026-11-13'], [42], [37])];
        yield 'all zero, without the previous range' => ['line-zero', new LineChart('zero', $title, $amount, self::days('2026-11-07', 3), [0, 0, 0])];
        yield 'bars' => ['bar', new BarChart('devices', new Message('Devices'), $amount, [['label' => 'Computer', 'value' => 812], ['label' => 'Phone <b>', 'value' => 498], ['label' => 'Tablet', 'value' => 1]])];
        yield 'bars of zero' => ['bar-zero', new BarChart('sources', new Message('Sources'), $amount, [['label' => 'Direct', 'value' => 0]])];
    }

    #[DataProvider('charts')]
    public function testDrawsTheGoldenSvg(string $golden, LineChart|BarChart $chart): void
    {
        $renderer = new ChartRenderer(Translations::fromDirectories([]));
        $svg = $chart instanceof LineChart ? $renderer->line($chart, 'en-GB') : $renderer->bar($chart, 'en-GB');
        $file = __DIR__ . '/golden/' . $golden . '.svg';
        if (getenv('SIGHTPRESS_UPDATE_GOLDEN') === '1') {
            file_put_contents($file, $svg . "\n");
        }

        self::assertSame((string) file_get_contents($file), $svg . "\n");
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($svg), 'well-formed');
        self::assertSame('img', $document->documentElement?->getAttribute('role'));
        self::assertSame($chart->id . '-summary', $document->documentElement->getAttribute('aria-describedby'));
        self::assertNotSame('', $document->documentElement->getAttribute('aria-label'));
        self::assertStringNotContainsString('viewBox', $svg, 'text keeps the page size');
        self::assertStringNotContainsString('<animate', $svg);
    }

    public function testEmptyChartsDrawNothing(): void
    {
        $renderer = new ChartRenderer(Translations::fromDirectories([]));

        self::assertSame('', $renderer->line(new LineChart('x', new Message('Visitors'), new Message('{count}'), [], []), 'en-GB'));
        self::assertSame('', $renderer->bar(new BarChart('x', new Message('Devices'), new Message('{count}'), []), 'en-GB'));
    }

    public function testLongRangesHaveNoMarkersAndTheScaleIsRound(): void
    {
        $renderer = new ChartRenderer(Translations::fromDirectories([]));
        $days = self::days('2026-08-01', 90);
        $svg = $renderer->line(new LineChart('quarter', new Message('Visitors'), new Message('{count}'), $days, array_fill(0, 90, 3), array_fill(0, 90, 2)), 'pt-PT');

        self::assertStringNotContainsString('<circle', $svg);
        self::assertStringNotContainsString('<rect', $svg);
        self::assertStringContainsString('>1/08<', $svg, 'the axis is in the viewer\'s language');
        self::assertSame(
            [4, 4, 6, 10, 12, 16, 20, 24, 30, 40, 50, 60, 80, 100, 1000, 1200, 10000],
            array_map(ChartRenderer::niceMax(...), [0, 3.5, 5, 10, 11, 13, 17, 21, 25, 31, 41, 51, 61, 81, 1000, 1001, 9000]),
        );
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" class="chart-inline-bar" aria-hidden="true" focusable="false" width="100%" height="0.75em"><rect class="chart__bar" width="50%" height="100%"/></svg>',
            $renderer->inlineBar(5, 10),
        );
        self::assertStringContainsString('width="0%"', $renderer->inlineBar(0, 0));
        self::assertStringContainsString('width="0.5%"', $renderer->inlineBar(1, 10000));
    }

    public function testRefusesSeriesThatDoNotFitTheDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LineChart('x', new Message('Visitors'), new Message('{count}'), ['2026-11-01'], [1], [1, 2]);
    }

    public function testFormatsNumbersPercentagesAndDaysPerLocale(): void
    {
        self::assertSame(['1,349', '12.5%', 'Tuesday 3 November', '3 Nov', '3 November 2026'], self::formats('en-GB'));
        self::assertSame(['1 349', '12,5%', 'terça-feira, 3 de novembro', '3/11', '3 de novembro de 2026'], self::formats('pt-PT'));
        self::assertSame(['1.349', '12,5 %', 'martes, 3 de noviembre', '3 nov', '3 de noviembre de 2026'], self::formats('es'));
    }

    /**
     * @return list<string>
     */
    private static function formats(string $locale): array
    {
        $format = new ChartFormat($locale);

        // ICU versions differ in which space they put in numbers (no-break or narrow no-break): compare them as spaces.
        return array_map(
            static fn (string $text): string => (string) preg_replace('/\h/u', ' ', $text),
            [$format->number(1349), $format->percent(-12.5), $format->day('2026-11-03'), $format->shortDay('2026-11-03'), $format->longDay('2026-11-03')],
        );
    }

    /**
     * @return list<string>
     */
    private static function days(string $from, int $count): array
    {
        return array_map(static fn (int $i): string => new \DateTimeImmutable($from)->modify("+{$i} days")->format('Y-m-d'), range(0, $count - 1));
    }
}
