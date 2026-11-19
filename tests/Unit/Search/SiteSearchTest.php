<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Search\SiteSearch;
use SightPress\Core\Tests\Support\RecordingEvents;
use SightPress\Core\Tests\Support\Search\SearchFixture;
use SightPress\Module\Search\SearchPerformed;
use SightPress\Module\Search\SearchQuery;

#[CoversClass(SiteSearch::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteSearchTest extends TestCase
{
    public function testBuildsTheQueryFromWhatSomeoneTyped(): void
    {
        $query = SiteSearch::query("  Book\t\n  FAIR\u{00A0}2026 ", 'pt-PT', true, 2, 20);

        self::assertNotNull($query);
        self::assertSame(['Book FAIR 2026', 'pt-PT', true, 2, 20], [$query->text, $query->locale, $query->includeMembersOnly, $query->page, $query->perPage]);
        self::assertNull(SiteSearch::query(" \t ", 'en-GB', false));
        self::assertSame(SearchQuery::MAX_LENGTH, mb_strlen(SiteSearch::query(str_repeat('é', 300), 'en-GB', false)->text ?? ''));
        self::assertSame(str_repeat('a', 199), SiteSearch::query(str_repeat('a', 199) . ' b', 'en-GB', false)?->text, 'shortened, then trimmed');
    }

    public function testSearchesAndTellsOtherModulesWithoutSayingWho(): void
    {
        $search = new SearchFixture();
        $search->provider->add('1', 'Book fair');
        $index = $search->index();
        $index->reindex();
        $events = new RecordingEvents();
        $site = new SiteSearch($index, $events);

        $results = $site->search(new SearchQuery('Book  Fair', 'en-GB'));
        self::assertSame(1, $results->total);
        self::assertEquals([new SearchPerformed('book fair', 'en-GB', 1)], $events->events);

        $site->search(new SearchQuery('nothing', 'en-GB'), count: false);
        self::assertCount(1, $events->events, 'site:search is not a visitor\'s search');
    }
}
