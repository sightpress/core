<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Analytics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Analytics\DailyAggregator;
use SightPress\Core\Analytics\Hit;

#[CoversClass(DailyAggregator::class)]
final class DailyAggregatorTest extends TestCase
{
    public function testCountsViewsVisitorsAndEachDimension(): void
    {
        $view = static fn (string $visitor, int $page, string $locale, string $source, string $device): array => [
            'kind' => Hit::VIEW, 'resource_kind' => 'page', 'page_id' => $page, 'locale' => $locale, 'visitor_key' => $visitor, 'source' => $source, 'device' => $device,
        ];
        $figures = new DailyAggregator()->aggregate([
            $view('a', 1, 'en-GB', 'direct', 'computer'),
            $view('a', 2, 'en-GB', 'direct', 'computer'),
            $view('b', 1, 'pt-PT', 'search:Google', 'phone'),
            ['kind' => Hit::NOT_FOUND, 'path' => '/About'],
            ['kind' => Hit::NOT_FOUND, 'path' => '/about'],
            ['kind' => Hit::NOT_FOUND, 'path' => '/about'],
            ['kind' => Hit::BOT, 'bot' => 'GPTBot', 'bot_kind' => 'ai_training', 'resource_kind' => 'llms'],
            ['kind' => Hit::SEARCH, 'search_query' => 'café', 'result_count' => 2],
            ['kind' => Hit::SEARCH, 'search_query' => 'cafe', 'result_count' => 0],
            ['kind' => Hit::SEARCH, 'search_query' => null, 'result_count' => 0],
            ['kind' => Hit::SEARCH, 'search_query' => '2026', 'result_count' => 1],
            ['kind' => 'something new'],
        ]);

        self::assertSame([
            ['bot_reads', 'bot', 'GPTBot|ai_training|llms', 1],
            ['empty_searches', '', '', 2],
            ['empty_searches', 'query', 'cafe', 1],
            ['not_found', 'path', '/About', 1],
            ['not_found', 'path', '/about', 2],
            ['searches', '', '', 4],
            ['searches', 'query', '2026', 1],
            ['searches', 'query', 'cafe', 1],
            ['searches', 'query', 'café', 1],
            ['views', '', '', 3],
            ['views', 'device', 'computer', 2],
            ['views', 'device', 'phone', 1],
            ['views', 'locale', 'en-GB', 2],
            ['views', 'locale', 'pt-PT', 1],
            ['views', 'page', '1:en-GB', 1],
            ['views', 'page', '1:pt-PT', 1],
            ['views', 'page', '2:en-GB', 1],
            ['views', 'source', 'direct', 2],
            ['views', 'source', 'search:Google', 1],
            ['visitors', '', '', 2],
            ['visitors', 'device', 'computer', 1],
            ['visitors', 'device', 'phone', 1],
            ['visitors', 'locale', 'en-GB', 1],
            ['visitors', 'locale', 'pt-PT', 1],
            ['visitors', 'page', '1:en-GB', 1],
            ['visitors', 'page', '1:pt-PT', 1],
            ['visitors', 'page', '2:en-GB', 1],
            ['visitors', 'source', 'direct', 1],
            ['visitors', 'source', 'search:Google', 1],
        ], array_map(static fn (array $figure): array => array_values($figure), $figures));
    }

    public function testNothingGivesNothing(): void
    {
        self::assertSame([], new DailyAggregator()->aggregate([]));
    }
}
