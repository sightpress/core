<?php

declare(strict_types=1);

namespace Acme\Events;

use Doctrine\DBAL\Connection;

/**
 * The module's own table, through the site's DBAL connection.
 */
final readonly class EventStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function add(string $slug, string $title, string $startsAt): void
    {
        $this->connection->insert('acme_events_event', ['slug' => $slug, 'title' => $title, 'starts_at' => $startsAt]);
    }

    /**
     * @return list<array{slug: string, title: string, starts_at: string}>
     */
    public function all(): array
    {
        /** @var list<array{slug: string, title: string, starts_at: string}> */
        return $this->connection->createQueryBuilder()->select('slug', 'title', 'starts_at')->from('acme_events_event')->orderBy('starts_at')->fetchAllAssociative();
    }

    /**
     * @return array{slug: string, title: string, starts_at: string}|null
     */
    public function find(string $slug): ?array
    {
        foreach ($this->all() as $event) {
            if ($event['slug'] === $slug) {
                return $event;
            }
        }

        return null;
    }
}
