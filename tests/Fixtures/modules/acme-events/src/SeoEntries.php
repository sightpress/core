<?php

declare(strict_types=1);

namespace Acme\Events;

use DateTimeImmutable;
use SightPress\Module\Seo\SeoEntry;
use SightPress\Module\Seo\SeoProvider;

/**
 * Every event in the sitemap and `llms.txt`, with `Event` JSON-LD. Events have one language: the site's default.
 */
final readonly class SeoEntries implements SeoProvider
{
    public function __construct(private EventStore $events)
    {
    }

    public function entries(string $locale): iterable
    {
        if ($locale !== 'en-GB') {
            return;
        }
        foreach ($this->events->all() as $event) {
            yield $this->entry($event);
        }
    }

    /**
     * @param array{slug: string, title: string, starts_at: string} $event
     */
    public function entry(array $event): SeoEntry
    {
        $path = '/events/' . $event['slug'];

        return new SeoEntry($path, 'en-GB', $event['title'], new DateTimeImmutable('2026-11-24T00:00:00Z'), 'An event at the venue.', $path . '.md', structuredData: [
            ['@type' => 'Event', 'name' => $event['title'], 'startDate' => $event['starts_at']],
        ]);
    }
}
