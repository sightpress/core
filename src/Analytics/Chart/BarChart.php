<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Chart;

use SightPress\Core\Template\Message;

/**
 * Amounts to draw as horizontal bars ({@see ChartRenderer::bar()}), largest first, each with its label written next
 * to it (no legend).
 */
final readonly class BarChart
{
    /**
     * @param non-empty-string                                 $id     start of the ids of the chart's parts
     * @param Message                                          $title  what is shown, for the chart's name: "Devices"
     * @param Message                                          $amount a number of what is counted, with `{count}`
     * @param list<array{label: string, value: int|float}>     $bars   already translated labels
     */
    public function __construct(
        public string $id,
        public Message $title,
        public Message $amount,
        public array $bars,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->bars === [];
    }

    public function total(): int|float
    {
        return array_sum(array_column($this->bars, 'value'));
    }
}
