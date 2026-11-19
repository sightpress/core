<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Search;

use DateTimeImmutable;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchDocumentProvider;

/**
 * A provider of search documents for tests, the way an external module would write one: events of the `acme.events`
 * module, by ref and locale.
 */
final class FakeSearchProvider implements SearchDocumentProvider
{
    /** @var array<string, array<string, SearchDocument>> locale => ref => document */
    public array $documents = [];

    /**
     * @param non-empty-string $source
     */
    public function __construct(private readonly string $source = 'acme.events')
    {
    }

    /**
     * @param non-empty-string $ref
     * @param non-empty-string $locale
     */
    public function add(string $ref, string $title, string $locale = 'en-GB', string $body = ''): SearchDocument
    {
        return $this->documents[$locale][$ref] = new SearchDocument($this->source, $ref, $locale, '/events/' . $ref, $title, new DateTimeImmutable('2026-11-01T10:00:00Z'), body: $body);
    }

    /**
     * @param non-empty-string $ref
     */
    public function drop(string $ref): void
    {
        foreach (array_keys($this->documents) as $locale) {
            unset($this->documents[$locale][$ref]);
        }
    }

    public function source(): string
    {
        return $this->source;
    }

    public function documents(string $locale): iterable
    {
        yield from array_values($this->documents[$locale] ?? []);
    }

    public function document(string $ref, string $locale): ?SearchDocument
    {
        return $this->documents[$locale][$ref] ?? null;
    }
}
