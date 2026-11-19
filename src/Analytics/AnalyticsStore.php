<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;

/**
 * The analytics tables of the site's database, through the DBAL QueryBuilder only: today's raw hits, the daily
 * figures and the daily salts (ADR-0012). Days are `Y-m-d` strings in the site's time zone, so they sort and
 * compare as text on every engine.
 */
final readonly class AnalyticsStore
{
    public const string HITS = 'analytics_hit';
    public const string DAILY = 'analytics_daily';
    public const string SALTS = 'analytics_salt';

    public function __construct(private Connection $connection)
    {
    }

    public function record(Hit $hit): void
    {
        $this->connection->insert(self::HITS, [
            'day_on' => $hit->day,
            'occurred_at' => $hit->at,
            'kind' => $hit->kind,
            'resource_kind' => $hit->resource,
            'page_id' => $hit->pageId,
            'locale' => $hit->locale,
            'path' => $hit->path,
            'visitor_key' => $hit->visitor,
            'source' => $hit->source,
            'device' => $hit->device,
            'bot' => $hit->bot,
            'bot_kind' => $hit->botKind,
            'search_query' => $hit->query,
            'result_count' => $hit->results,
        ], ['occurred_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * The day's salt, made (random) the first time it is asked for. Two requests making it at once both get the one
     * that was stored.
     */
    public function salt(string $day): string
    {
        $stored = $this->storedSalt($day);
        if ($stored !== null) {
            return $stored;
        }
        $salt = bin2hex(random_bytes(32));
        try {
            $this->connection->insert(self::SALTS, ['day_on' => $day, 'salt' => $salt]);
        } catch (UniqueConstraintViolationException) {
            return $this->storedSalt($day) ?? $salt;
        }

        return $salt;
    }

    /**
     * Days before `$today` that still have raw hits or a salt, oldest first.
     *
     * @return list<string>
     */
    public function unfinishedDaysBefore(string $today): array
    {
        $days = [];
        foreach ([self::HITS, self::SALTS] as $table) {
            $rows = $this->connection->createQueryBuilder()
                ->select('day_on')->distinct()->from($table)
                ->where('day_on < :today')->setParameter('today', $today)
                ->executeQuery()->fetchFirstColumn();
            foreach ($rows as $day) {
                $days[self::text($day)] = true;
            }
        }
        $days = array_map(strval(...), array_keys($days));
        sort($days, SORT_STRING);

        return $days;
    }

    /**
     * The raw hits of a day, as rows of column => value.
     *
     * @return iterable<array<string, mixed>>
     */
    public function hits(string $day): iterable
    {
        return $this->connection->createQueryBuilder()
            ->select('kind', 'resource_kind', 'page_id', 'locale', 'path', 'visitor_key', 'source', 'device', 'bot', 'bot_kind', 'search_query', 'result_count')
            ->from(self::HITS)
            ->where('day_on = :day')->setParameter('day', $day)
            ->orderBy('id')
            ->executeQuery()->iterateAssociative();
    }

    /**
     * The stored figures of one day.
     *
     * @return list<array{metric: string, dimension: string, item: string, value: int}>
     */
    public function dayFigures(string $day): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('metric', 'dimension', 'item', 'amount')
            ->from(self::DAILY)
            ->where('day_on = :day')->setParameter('day', $day)
            ->orderBy('id')
            ->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'metric' => self::text($row['metric']),
            'dimension' => self::text($row['dimension']),
            'item' => self::text($row['item']),
            'value' => (int) self::text($row['amount']),
        ], $rows);
    }

    /**
     * Stores a finished day's figures in place of any it had, and deletes its raw hits and salt, in one transaction.
     *
     * @param list<array{metric: string, dimension: string, item: string, value: int}> $figures
     */
    public function finishDay(string $day, array $figures): void
    {
        $this->connection->transactional(function (Connection $connection) use ($day, $figures): void {
            foreach ([self::DAILY, self::HITS, self::SALTS] as $table) {
                $connection->createQueryBuilder()->delete($table)->where('day_on = :day')->setParameter('day', $day)->executeStatement();
            }
            foreach ($figures as $figure) {
                $connection->insert(self::DAILY, [
                    'day_on' => $day,
                    'metric' => $figure['metric'],
                    'dimension' => $figure['dimension'],
                    'item' => $figure['item'],
                    'amount' => $figure['value'],
                ]);
            }
        });
    }

    /**
     * The stored figures of one metric and dimension from `$from` to `$to` (both included).
     *
     * @param list<string> $metrics
     *
     * @return list<array{day: string, metric: string, item: string, value: int}>
     */
    public function figures(array $metrics, string $dimension, string $from, string $to): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('day_on', 'metric', 'item', 'amount')
            ->from(self::DAILY)
            ->where('metric IN (:metrics)', 'dimension = :dimension', 'day_on >= :from', 'day_on <= :to')
            ->setParameter('metrics', $metrics, ArrayParameterType::STRING)
            ->setParameter('dimension', $dimension)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'day' => self::text($row['day_on']),
            'metric' => self::text($row['metric']),
            'item' => self::text($row['item']),
            'value' => (int) self::text($row['amount']),
        ], $rows);
    }

    /**
     * Deletes the figures, raw hits and salts of every day before `$day`, and says how many daily figures went.
     */
    public function deleteBefore(string $day): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($day): int {
            $deleted = 0;
            foreach ([self::DAILY, self::HITS, self::SALTS] as $table) {
                $count = (int) $connection->createQueryBuilder()->delete($table)->where('day_on < :day')->setParameter('day', $day)->executeStatement();
                $deleted += $table === self::DAILY ? $count : 0;
            }

            return $deleted;
        });
    }

    /**
     * Deletes everything analytics has stored for the site.
     */
    public function deleteAll(): void
    {
        $this->connection->transactional(function (Connection $connection): void {
            foreach ([self::DAILY, self::HITS, self::SALTS] as $table) {
                $connection->createQueryBuilder()->delete($table)->executeStatement();
            }
        });
    }

    /**
     * The oldest day with figures or raw hits, or null when there is nothing yet.
     */
    public function firstDay(): ?string
    {
        $first = null;
        foreach ([self::DAILY, self::HITS] as $table) {
            $day = $this->connection->createQueryBuilder()->select('day_on')->from($table)->orderBy('day_on')->setMaxResults(1)->executeQuery()->fetchOne();
            if (is_string($day) && $day !== '' && ($first === null || $day < $first)) {
                $first = $day;
            }
        }

        return $first;
    }

    private function storedSalt(string $day): ?string
    {
        $salt = $this->connection->createQueryBuilder()
            ->select('salt')->from(self::SALTS)
            ->where('day_on = :day')->setParameter('day', $day)
            ->executeQuery()->fetchOne();

        return is_string($salt) && $salt !== '' ? $salt : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
