<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Search\SearchBackend;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchQuery;
use SightPress\Module\Search\SearchResults;

final class ExampleSearchBackend implements SearchBackend
{
    public function index(SearchDocument $document): void
    {
    }

    public function remove(string $source, string $ref, ?string $locale = null): void
    {
    }

    public function clear(?string $locale = null): void
    {
    }

    public function query(SearchQuery $query): SearchResults
    {
        return new SearchResults($query);
    }

    public function status(): array
    {
        return [];
    }
}
