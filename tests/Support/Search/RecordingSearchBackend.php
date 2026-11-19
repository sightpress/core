<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Search;

use SightPress\Module\Search\SearchBackend;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchHit;
use SightPress\Module\Search\SearchQuery;
use SightPress\Module\Search\SearchResults;

/**
 * A search back-end for tests that keeps documents in memory and writes down what it was asked to do, the way a
 * module replacing core's back-end would plug in. A query finds documents whose title contains the text.
 */
final class RecordingSearchBackend implements SearchBackend
{
    /** @var array<string, SearchDocument> by key */
    public array $documents = [];

    /** @var list<string> */
    public array $calls = [];

    public function index(SearchDocument $document): void
    {
        $this->calls[] = 'index ' . $document->key();
        $this->documents[$document->key()] = $document;
    }

    public function remove(string $source, string $ref, ?string $locale = null): void
    {
        $this->calls[] = sprintf('remove %s:%s:%s', $source, $ref, $locale ?? '*');
        foreach ($this->documents as $key => $document) {
            if ($document->source === $source && $document->ref === $ref && ($locale === null || $document->locale === $locale)) {
                unset($this->documents[$key]);
            }
        }
    }

    public function clear(?string $locale = null): void
    {
        $this->calls[] = 'clear ' . ($locale ?? '*');
        $this->documents = array_filter($this->documents, static fn (SearchDocument $document): bool => $locale !== null && $document->locale !== $locale);
    }

    public function query(SearchQuery $query): SearchResults
    {
        $hits = [];
        foreach ($this->documents as $document) {
            if ($document->locale === $query->locale && $query->allows($document->visibility) && stripos($document->title, $query->text) !== false) {
                $hits[] = new SearchHit($document, 1.0, [$query->text]);
            }
        }

        return new SearchResults($query, array_slice($hits, 0, $query->perPage), count($hits));
    }

    public function status(): array
    {
        return [];
    }
}
