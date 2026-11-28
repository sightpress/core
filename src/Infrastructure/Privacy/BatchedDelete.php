<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Privacy;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Deletes the rows a query finds, a batch at a time (GD-2): it reads at most `$batch` ids, deletes them, and goes on
 * until none are left. Each batch is its own statement, so a run that is stopped halfway can simply run again.
 */
final class BatchedDelete
{
    /**
     * @param Closure(QueryBuilder): QueryBuilder $where adds the conditions to `SELECT <id> FROM <table>`
     * @param positive-int                        $batch
     *
     * @return int how many rows were deleted
     */
    public static function run(Connection $connection, string $table, string $id, Closure $where, int $batch = 500): int
    {
        $deleted = 0;
        do {
            $ids = $where($connection->createQueryBuilder()->select($id)->from($table))
                ->orderBy($id)
                ->setMaxResults($batch)
                ->fetchFirstColumn();
            if ($ids === []) {
                break;
            }
            $deleted += (int) $connection->createQueryBuilder()
                ->delete($table)
                ->where(sprintf('%s IN (:ids)', $id))
                ->setParameter('ids', $ids, is_int($ids[0]) || is_numeric($ids[0]) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING)
                ->executeStatement();
        } while (count($ids) === $batch);

        return $deleted;
    }
}
