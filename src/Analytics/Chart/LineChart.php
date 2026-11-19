<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Chart;

use InvalidArgumentException;
use SightPress\Core\Template\Message;

/**
 * A daily series to draw as a line ({@see ChartRenderer::line()}): one value per day of a range, and optionally the
 * same number of days just before it, drawn dashed with square markers so the two differ by more than colour.
 */
final readonly class LineChart
{
    /**
     * @param non-empty-string   $id       start of the ids of the chart's parts (`<id>-summary`), unique on the page
     * @param Message            $title    what is counted, as the start of a sentence: "Visitors"
     * @param Message            $amount   a number of them, with `{count}`: "{count, plural, one {# visitor} other
     *                                     {# visitors}}"
     * @param list<string>       $days     `Y-m-d`, in order
     * @param list<int|float>    $values   one per day
     * @param list<int|float>|null $previous one per day of the previous range, or null to draw only this range
     */
    public function __construct(
        public string $id,
        public Message $title,
        public Message $amount,
        public array $days,
        public array $values,
        public ?array $previous = null,
    ) {
        if (count($values) !== count($days) || ($previous !== null && count($previous) !== count($days))) {
            throw new InvalidArgumentException('A line chart needs one value per day, in each range.');
        }
    }

    /**
     * No days at all: there is nothing to draw, only a sentence to say so.
     */
    public function isEmpty(): bool
    {
        return $this->days === [];
    }

    public function total(): int|float
    {
        return array_sum($this->values);
    }

    public function previousTotal(): int|float|null
    {
        return $this->previous === null ? null : array_sum($this->previous);
    }
}
