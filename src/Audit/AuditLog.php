<?php

declare(strict_types=1);

namespace SightPress\Core\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;

/**
 * The site's audit log in its `audit_log` table. Append-only: there is no way here to change or remove an entry.
 */
final readonly class AuditLog
{
    public const string TABLE = 'audit_log';

    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditEntry $entry): void
    {
        $this->connection->insert(self::TABLE, [
            'occurred_at' => $entry->occurredAt,
            'actor_id' => $entry->actorId,
            'event_action' => $entry->action,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'summary' => mb_substr($entry->summary, 0, 500),
            'details' => $entry->details === [] ? null : json_encode($entry->details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], ['occurred_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * The entries that match, newest first.
     *
     * @return list<AuditEntry>
     */
    public function find(AuditFilter $filter, int $limit, int $offset = 0): array
    {
        $rows = $this->filtered($filter)
            ->select('*')
            ->orderBy('occurred_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->entry(...), $rows);
    }

    public function count(AuditFilter $filter): int
    {
        $count = $this->filtered($filter)->select('COUNT(id)')->executeQuery()->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The ids of everyone who did something, for the "Who" filter.
     *
     * @return list<int>
     */
    public function actors(): array
    {
        $ids = $this->connection->createQueryBuilder()
            ->select('DISTINCT actor_id')
            ->from(self::TABLE)
            ->where('actor_id IS NOT NULL')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids));
    }

    private function filtered(AuditFilter $filter): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()->from(self::TABLE);
        if ($filter->actorId !== null) {
            $query->andWhere('actor_id = :actor')->setParameter('actor', $filter->actorId);
        }
        if ($filter->entityType !== null) {
            $query->andWhere('entity_type = :type')->setParameter('type', $filter->entityType);
        }
        if ($filter->from !== null) {
            $query->andWhere('occurred_at >= :from')->setParameter('from', $filter->from, Types::DATETIME_IMMUTABLE);
        }
        if ($filter->before !== null) {
            $query->andWhere('occurred_at < :before')->setParameter('before', $filter->before, Types::DATETIME_IMMUTABLE);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function entry(array $row): AuditEntry
    {
        $occurred = $this->connection->convertToPHPValue($row['occurred_at'] ?? null, Types::DATETIME_IMMUTABLE);
        $details = is_string($row['details'] ?? null) ? json_decode($row['details'], true) : null;
        $text = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        $int = static fn (string $key): ?int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
        /** @var array<string, mixed> $details */
        $details = is_array($details) ? $details : [];

        return new AuditEntry(
            $occurred instanceof DateTimeImmutable ? $occurred : new DateTimeImmutable('@0'),
            $int('actor_id'),
            $text('event_action'),
            $text('entity_type'),
            ($row['entity_id'] ?? null) === null ? null : $text('entity_id'),
            $text('summary'),
            $details,
            $int('id'),
        );
    }
}
