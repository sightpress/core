<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\PendingSearches;
use SightPress\Core\Analytics\QueryRedactor;
use SightPress\Core\Analytics\SearchListener;
use SightPress\Module\Search\SearchPerformed;

#[CoversClass(SearchListener::class)]
#[CoversClass(PendingSearches::class)]
#[UsesClass(QueryRedactor::class)]
final class SearchListenerTest extends TestCase
{
    public function testKeepsEachSearchWithoutPersonalDataUntilTheRequestIsAnswered(): void
    {
        $pending = new PendingSearches();
        $listener = new SearchListener($pending, new QueryRedactor());

        $listener(new SearchPerformed('Opening Hours', 'en-GB', 3));
        $listener(new SearchPerformed('ada@example.org', 'pt-PT', 0));

        self::assertSame([
            ['query' => 'opening hours', 'locale' => 'en-GB', 'results' => 3],
            ['query' => null, 'locale' => 'pt-PT', 'results' => 0],
        ], $pending->take());
        self::assertSame([], $pending->take(), 'taken once');
    }
}
