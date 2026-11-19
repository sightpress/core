<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * The `assistant_usage` table, through the DBAL QueryBuilder only.
 */
final readonly class AssistantUsageStore
{
    public const string TABLE = 'assistant_usage';

    public function __construct(private Connection $connection)
    {
    }

    public function record(AssistantUse $use): void
    {
        $this->connection->insert(self::TABLE, [
            'used_at' => $use->at,
            'month' => $use->month(),
            'user_id' => $use->userId,
            'task' => $use->task,
            'provider' => $use->provider,
            'model' => mb_substr($use->model, 0, 200),
            'input_tokens' => $use->usage->inputTokens,
            'output_tokens' => $use->usage->outputTokens,
            'outcome' => $use->outcome,
        ], ['used_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Tokens used in a month (input and output together).
     *
     * @return int<0, max>
     */
    public function tokens(string $month): int
    {
        $total = $this->connection->createQueryBuilder()
            ->select('SUM(input_tokens) + SUM(output_tokens)')
            ->from(self::TABLE)
            ->where('month = :month')
            ->setParameter('month', $month)
            ->fetchOne();

        return max(0, (int) (is_numeric($total) ? $total : 0));
    }

    /**
     * A month's uses per task: how many, how many failed, and the tokens.
     *
     * @return list<array{task: string, uses: int, failed: int, input: int, output: int}> most tokens first
     */
    public function byTask(string $month): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('task', 'outcome', 'input_tokens', 'output_tokens')
            ->from(self::TABLE)
            ->where('month = :month')
            ->setParameter('month', $month)
            ->fetchAllAssociative();
        $tasks = [];
        foreach ($rows as $row) {
            $task = is_scalar($row['task']) ? (string) $row['task'] : '';
            $tasks[$task] ??= ['task' => $task, 'uses' => 0, 'failed' => 0, 'input' => 0, 'output' => 0];
            ++$tasks[$task]['uses'];
            $tasks[$task]['failed'] += $row['outcome'] === AssistantUse::OK ? 0 : 1;
            $tasks[$task]['input'] += is_numeric($row['input_tokens']) ? (int) $row['input_tokens'] : 0;
            $tasks[$task]['output'] += is_numeric($row['output_tokens']) ? (int) $row['output_tokens'] : 0;
        }
        $tasks = array_values($tasks);
        usort($tasks, static fn (array $a, array $b): int => [$b['input'] + $b['output'], $a['task']] <=> [$a['input'] + $a['output'], $b['task']]);

        return $tasks;
    }
}
