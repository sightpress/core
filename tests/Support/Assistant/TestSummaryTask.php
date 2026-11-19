<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Assistant;

/**
 * Stands in for the `summary` task in tests ({@see TestSuggestTask}).
 */
final class TestSummaryTask extends TestSuggestTask
{
    public function id(): string
    {
        return 'summary';
    }
}
