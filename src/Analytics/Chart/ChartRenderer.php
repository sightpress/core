<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Chart;

use Closure;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;

/**
 * Draws charts as SVG on the server: no JavaScript, no chart library, no animation (ADR-0038).
 *
 * Positions are percentages of the chart's width and height and there is no `viewBox`, so the chart reflows to any
 * width (320 px included) while its `<text>` keeps the page's own font size and grows with zoom. Each chart is
 * `role="img"`, named by `aria-label` and described by its summary (`aria-describedby="<id>-summary"`); the caller
 * shows the summary in the `<figcaption>` and the data in a table. Colours come from the theme's chart tokens through
 * CSS classes (`chart__…`); the two series of a line chart also differ by line (solid, dashed) and marker (circles,
 * squares). An empty chart draws nothing: show its summary sentence instead.
 */
final readonly class ChartRenderer
{
    /** Markers are drawn up to this many days; beyond, they would touch and only the lines are drawn. */
    public const int MARKERS_UP_TO = 31;

    private const float LEFT = 2.0;
    private const float RIGHT = 98.0;
    private const float TOP = 14.0;
    private const float BOTTOM = 84.0;

    /** Height of one bar's row, in em: its label above, the bar under it. */
    private const float ROW = 2.75;

    public function __construct(private Translations $translations)
    {
    }

    public function line(LineChart $chart, string $locale): string
    {
        if ($chart->isEmpty()) {
            return '';
        }
        $format = new ChartFormat($locale);
        $count = count($chart->days);
        $max = self::niceMax(max([0, ...$chart->values, ...($chart->previous ?? [])]));
        $x = static fn (int $index): float => $count === 1 ? 50.0 : self::LEFT + (self::RIGHT - self::LEFT) * $index / ($count - 1);
        $y = static fn (int|float $value): float => self::BOTTOM - (self::BOTTOM - self::TOP) * $value / $max;
        $days = $chart->days;
        $last = (string) end($days);
        $label = $this->translations->translate($count === 1
            ? new Message('Line chart of {what} on {day}', ['what' => $chart->title->lowerFirst(), 'day' => $format->longDay($chart->days[0])])
            : new Message('Line chart of {what} per day, {from} to {to}', [
                'what' => $chart->title->lowerFirst(),
                'from' => $format->longDay($chart->days[0]),
                'to' => $format->longDay($last),
            ]), [], $locale);

        $svg = self::open($chart->id, $label, 'line');
        $svg .= '<g class="chart__grid">';
        $ticks = [0, $max / 2, $max];
        foreach ($ticks as $tick) {
            $svg .= sprintf('<line x1="0" x2="100%%" y1="%1$s%%" y2="%1$s%%"/>', self::n($y($tick)));
        }
        $svg .= '</g><g class="chart__text">';
        foreach ($ticks as $tick) {
            $text = $tick === $max ? $this->translations->translate($chart->amount, ['count' => $tick], $locale) : $format->number($tick);
            $svg .= sprintf('<text x="0" y="%s%%" dy="-0.4em">%s</text>', self::n($y($tick)), self::e($text));
        }
        $labelled = $count === 1 ? [0] : array_values(array_unique([0, intdiv($count - 1, 2), $count - 1]));
        foreach ($labelled as $index) {
            $anchor = match (true) {
                $count === 1 || ($index !== 0 && $index !== $count - 1) => 'middle',
                $index === 0 => 'start',
                default => 'end',
            };
            $svg .= sprintf('<text x="%s%%" y="100%%" dy="-0.3em" text-anchor="%s">%s</text>', self::n($x($index)), $anchor, self::e($format->shortDay($chart->days[$index])));
        }
        $svg .= '</g>';
        $markers = $count <= self::MARKERS_UP_TO;
        if ($chart->previous !== null) {
            $svg .= self::series('previous', $chart->previous, $x, $y, $markers ? 'square' : null);
        }
        $svg .= self::series('current', $chart->values, $x, $y, $markers ? 'circle' : null);

        return $svg . '</svg>';
    }

    public function bar(BarChart $chart, string $locale): string
    {
        if ($chart->isEmpty()) {
            return '';
        }
        $format = new ChartFormat($locale);
        $max = max([0, ...array_column($chart->bars, 'value')]);
        $label = $this->translations->translate(new Message('Bar chart: {title}', ['title' => $chart->title]), [], $locale);
        $svg = self::open($chart->id, $label, 'bar', sprintf(' height="%sem"', self::n(count($chart->bars) * self::ROW)));
        foreach ($chart->bars as $index => $bar) {
            $top = $index * self::ROW;
            $width = (float) $max === 0.0 ? 0.0 : 100 * $bar['value'] / $max;
            $svg .= sprintf(
                '<text class="chart__text" x="0" y="%sem">%s</text><rect class="chart__bar" x="0" y="%sem" width="%s%%" height="0.9em"/>',
                self::n($top + 1.1),
                self::e($bar['label'] . ': ' . $this->translations->translate($chart->amount, ['count' => $bar['value']], $locale)),
                self::n($top + 1.5),
                self::n(max($width, 0.5)),
            );
        }

        return $svg . '</svg>';
    }

    /**
     * A small decorative bar for a cell of a table that already holds the number: hidden from assistive technology.
     */
    public function inlineBar(int|float $value, int|float $max): string
    {
        $width = (float) $max === 0.0 ? 0.0 : max(0.5, min(100.0, 100 * $value / $max));

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" class="chart-inline-bar" aria-hidden="true" focusable="false" width="100%%" height="0.75em"><rect class="chart__bar" width="%s%%" height="100%%"/></svg>', self::n($width));
    }

    private static function open(string $id, string $label, string $type, string $attributes = ''): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="chart__svg chart__svg--%s" role="img" aria-label="%s" aria-describedby="%s-summary" focusable="false"%s>',
            $type,
            self::e($label),
            self::e($id),
            $attributes,
        );
    }

    /**
     * @param list<int|float>          $values
     * @param Closure(int): float     $x
     * @param Closure(int|float): float $y
     */
    private static function series(string $name, array $values, Closure $x, Closure $y, ?string $marker): string
    {
        $svg = sprintf('<g class="chart__series chart__series--%s">', $name);
        $count = count($values);
        for ($index = 1; $index < $count; $index++) {
            $svg .= sprintf('<line x1="%s%%" y1="%s%%" x2="%s%%" y2="%s%%"/>', self::n($x($index - 1)), self::n($y($values[$index - 1])), self::n($x($index)), self::n($y($values[$index])));
        }
        foreach ($values as $index => $value) {
            $svg .= match ($marker) {
                'circle' => sprintf('<circle cx="%s%%" cy="%s%%" r="5"/>', self::n($x($index)), self::n($y($value))),
                'square' => sprintf('<svg x="%s%%" y="%s%%" overflow="visible"><rect x="-4.5" y="-4.5" width="9" height="9"/></svg>', self::n($x($index)), self::n($y($value))),
                default => '',
            };
        }

        return $svg . '</g>';
    }

    /**
     * The top of the scale: a round number at least as big as the largest value, whose half is round too (4 at
     * least, so a chart of zeros still has a scale).
     */
    public static function niceMax(int|float $value): int
    {
        if ($value <= 4) {
            return 4;
        }
        if ($value <= 10) {
            return (int) (ceil($value / 2) * 2);
        }
        $power = 10 ** (int) floor(log10($value));
        foreach ([1, 1.2, 1.6, 2, 2.4, 3, 4, 5, 6, 8] as $step) {
            if ($step * $power >= $value) {
                return (int) round($step * $power);
            }
        }

        return 10 * $power;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2F', $value), '0'), '.');
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
