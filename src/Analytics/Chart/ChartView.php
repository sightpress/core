<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Chart;

use DateTimeImmutable;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;

/**
 * What the chart macros (`@analytics/charts.html.twig`) show for one chart, all in the viewer's language: the SVG,
 * the summary for the `<figcaption>`, a key that names each series by its line and marker, and the same data as a
 * table. The data is never only in the chart.
 */
final readonly class ChartView
{
    public function __construct(
        private ChartRenderer $renderer,
        private ChartSummary $summary,
        private Translations $translations,
    ) {
    }

    /**
     * @param Message $current  name of this range in the key and the table: "Last 30 days"
     * @param Message $previous name of the range before it: "The 30 days before"
     *
     * @return array{id: string, empty: bool, svg: string, summary: string, key: list<array{series: string, label: string}>, disclosure: string, caption: string, columns: list<string>, rows: list<list<string>>}
     */
    public function line(LineChart $chart, string $locale, Message $current, Message $previous): array
    {
        $format = new ChartFormat($locale);
        $t = fn (Message $message): string => $this->translations->translate($message, [], $locale);
        $key = [['series' => 'current', 'label' => $t(new Message('{range}: solid line with round markers', ['range' => $current]))]];
        $columns = [$t(new Message('Day')), $t($chart->title)];
        if ($chart->previous !== null) {
            $key[] = ['series' => 'previous', 'label' => $t(new Message('{range}: dashed line with square markers', ['range' => $previous]))];
            $columns[] = $t($previous);
        }
        $offset = count($chart->days);
        $rows = [];
        foreach ($chart->days as $index => $day) {
            $row = [$format->day($day), $format->number($chart->values[$index])];
            if ($chart->previous !== null) {
                $before = new DateTimeImmutable($day)->modify(sprintf('-%d days', $offset))->format('Y-m-d');
                $row[] = $t(new Message('{amount} on {day}', ['amount' => $format->number($chart->previous[$index]), 'day' => $format->day($before)]));
            }
            $rows[] = $row;
        }

        return [
            'id' => $chart->id,
            'empty' => $chart->isEmpty(),
            'svg' => $this->renderer->line($chart, $locale),
            'summary' => $this->summary->line($chart, $locale),
            'key' => $key,
            'disclosure' => $t(new Message('Show the data for each day')),
            'caption' => $t(new Message('{title} for each day', ['title' => $chart->title])),
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * @param Message $label  heading of the first column: "Device"
     *
     * @return array{id: string, empty: bool, svg: string, summary: string, caption: string, columns: list<string>, rows: list<array{label: string, value: string, bar: string}>}
     */
    public function bar(BarChart $chart, string $locale, Message $label): array
    {
        $format = new ChartFormat($locale);
        $max = max([0, ...array_column($chart->bars, 'value')]);
        $rows = array_map(fn (array $bar): array => [
            'label' => $bar['label'],
            'value' => $format->number($bar['value']),
            'bar' => $this->renderer->inlineBar($bar['value'], $max),
        ], $chart->bars);

        return [
            'id' => $chart->id,
            'empty' => $chart->isEmpty(),
            'svg' => $this->renderer->bar($chart, $locale),
            'summary' => $this->summary->bar($chart, $locale),
            'caption' => $this->translations->translate($chart->title, [], $locale),
            'columns' => [$this->translations->translate($label, [], $locale), $this->translations->translate(new Message('Number'), [], $locale)],
            'rows' => $rows,
        ];
    }
}
