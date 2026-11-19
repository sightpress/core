<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Report;

/**
 * A number for a range and the same number for the previous range of the same length, with the change between them.
 */
final readonly class Figure
{
    public function __construct(
        public int|float $current,
        public int|float $previous,
    ) {
    }

    /**
     * The change in per cent, rounded to one decimal: 0 when both are 0, null when there was nothing before (an
     * increase from zero has no percentage).
     */
    public function change(): ?float
    {
        if ((float) $this->previous === 0.0) {
            return (float) $this->current === 0.0 ? 0.0 : null;
        }

        return round(($this->current - $this->previous) / $this->previous * 100, 1);
    }

    /**
     * @return array{current: int|float, previous: int|float, change: float|null}
     */
    public function toArray(): array
    {
        return ['current' => $this->current, 'previous' => $this->previous, 'change' => $this->change()];
    }
}
