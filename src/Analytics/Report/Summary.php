<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Report;

/**
 * The headline figures of a range: visitors (each counted once per day), page views and views per visitor, each
 * with the previous range of the same length.
 */
final readonly class Summary
{
    public function __construct(
        public DateRange $range,
        public Figure $visitors,
        public Figure $views,
        public Figure $viewsPerVisitor,
    ) {
    }
}
