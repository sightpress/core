<?php

declare(strict_types=1);

namespace Acme\Events;

use DateTimeImmutable;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchDocumentProvider;

final readonly class SearchDocuments implements SearchDocumentProvider
{
    public function __construct(private EventStore $events)
    {
    }

    public function source(): string
    {
        return 'acme.events';
    }

    public function documents(string $locale): iterable
    {
        foreach ($this->events->all() as $event) {
            $document = $event['slug'] === '' ? null : $this->document($event['slug'], $locale);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    public function document(string $ref, string $locale): ?SearchDocument
    {
        $event = $locale === 'en-GB' ? $this->events->find($ref) : null;

        return $event === null ? null : new SearchDocument($this->source(), $ref, $locale, '/events/' . $ref, $event['title'], new DateTimeImmutable('2026-11-24T00:00:00Z'), body: 'Starts at ' . $event['starts_at'] . '.');
    }
}
