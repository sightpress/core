<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Chart;

use SightPress\Core\Analytics\Report\Figure;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\Translations;

/**
 * The plain-language summary every chart carries in its `<figcaption>`, in the viewer's language: the total, the
 * change compared with the previous period with its direction in words, and the busiest and quietest days. For
 * example "Visitors went up 12% compared with the previous 30 days, from 1,204 to 1,349. The busiest day was
 * Tuesday 4 November, with 98 visitors."
 */
final readonly class ChartSummary
{
    public function __construct(private Translations $translations)
    {
    }

    public function line(LineChart $chart, string $locale): string
    {
        $format = new ChartFormat($locale);
        $days = count($chart->days);
        $total = $chart->total();
        $previous = $chart->previousTotal();
        $amount = fn (int|float $count): string => $this->translations->translate($chart->amount, ['count' => $count], $locale);
        if ($chart->isEmpty()) {
            return $this->text(new Message('There is nothing to show for these dates.'), $locale);
        }
        if ((float) $total === 0.0 && (float) ($previous ?? 0) === 0.0) {
            return $this->text(new Message('There is nothing to show for {days, plural, one {this day} other {these # days}}.', ['days' => $days]), $locale);
        }
        $title = $chart->title;
        $values = ['title' => $title, 'days' => $days, 'before' => $format->number($previous ?? 0), 'after' => $format->number($total)];
        $change = $previous === null ? null : new Figure($total, $previous)->change();
        $sentences = [match (true) {
            $previous === null => new Message('{title}: {amount} {days, plural, one {on this day} other {in these # days}}.', ['title' => $title, 'amount' => $amount($total), 'days' => $days]),
            (float) $previous === 0.0 => new Message('{title}: {amount} {days, plural, one {on this day} other {in these # days}}, and none {days, plural, one {on the previous day} other {in the previous # days}}.', ['title' => $title, 'amount' => $amount($total), 'days' => $days]),
            $change > 0 => new Message('{title} went up {change} compared with {days, plural, one {the previous day} other {the previous # days}}, from {before} to {after}.', [...$values, 'change' => $format->percent($change)]),
            $change < 0 => new Message('{title} went down {change} compared with {days, plural, one {the previous day} other {the previous # days}}, from {before} to {after}.', [...$values, 'change' => $format->percent($change)]),
            default => new Message('{title} stayed the same as {days, plural, one {on the previous day} other {in the previous # days}}: {after}.', $values),
        }];
        if ($days > 1 && (float) $total > 0.0 && $chart->values !== []) {
            $highest = max($chart->values);
            $lowest = min($chart->values);
            $sentences[] = new Message('The busiest day was {day}, with {amount}.', ['day' => $format->day($chart->days[(int) array_search($highest, $chart->values, true)]), 'amount' => $amount($highest)]);
            if ($lowest < $highest) {
                $sentences[] = new Message('The quietest day was {day}, with {amount}.', ['day' => $format->day($chart->days[(int) array_search($lowest, $chart->values, true)]), 'amount' => $amount($lowest)]);
            }
        }

        return implode(' ', array_map(fn (Message $sentence): string => $this->text($sentence, $locale), $sentences));
    }

    public function bar(BarChart $chart, string $locale): string
    {
        $total = $chart->total();
        if ($chart->isEmpty() || (float) $total === 0.0) {
            return $this->text(new Message('There is nothing to show yet.'), $locale);
        }
        $format = new ChartFormat($locale);
        $first = $chart->bars[0];
        foreach ($chart->bars as $bar) {
            $first = $bar['value'] > $first['value'] ? $bar : $first;
        }
        $amount = $this->translations->translate($chart->amount, ['count' => $first['value']], $locale);
        if (count($chart->bars) === 1) {
            return $this->text(new Message('All of them: {label}, with {amount}.', ['label' => $first['label'], 'amount' => $amount]), $locale);
        }

        return $this->text(new Message('The most: {label}, with {amount} ({share} of all).', [
            'label' => $first['label'],
            'amount' => $amount,
            'share' => $format->percent($first['value'] / $total * 100),
        ]), $locale);
    }

    private function text(Message $message, string $locale): string
    {
        return $this->translations->translate($message, [], $locale);
    }
}
