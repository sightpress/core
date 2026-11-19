<?php

declare(strict_types=1);

namespace SightPress\Core\Search;

use Psr\EventDispatcher\EventDispatcherInterface;
use SightPress\Module\Search\SearchPerformed;
use SightPress\Module\Search\SearchQuery;
use SightPress\Module\Search\SearchResults;

/**
 * Searching the site for visitors: what they typed (shortened to {@see SearchQuery::MAX_LENGTH} characters) in one
 * of the site's languages, members-only documents only for people signed in to the site. Each search dispatches
 * {@see SearchPerformed} (nothing about who searched); core stores no queries.
 */
final readonly class SiteSearch
{
    public function __construct(
        private SearchIndex $index,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * The query for what someone typed, or null when there is nothing to search for.
     *
     * @param non-empty-string $locale
     * @param positive-int     $page
     */
    public static function query(string $text, string $locale, bool $signedIn, int $page = 1, int $perPage = 10): ?SearchQuery
    {
        $text = trim(mb_substr(trim((string) preg_replace('/[\s\p{Z}\p{Cc}]+/u', ' ', $text)), 0, SearchQuery::MAX_LENGTH));

        return $text === '' ? null : new SearchQuery($text, $locale, $signedIn, $page, $perPage);
    }

    /**
     * @param bool $count false for searches that aren't a visitor's (`site:search`), so no event is dispatched
     */
    public function search(SearchQuery $query, bool $count = true): SearchResults
    {
        $results = $this->index->backend()->query($query);
        if ($count) {
            $this->events->dispatch(new SearchPerformed($query->text, $query->locale, $results->total));
        }

        return $results;
    }
}
