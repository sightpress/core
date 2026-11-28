<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Assistant\AssistantUsageStore;
use SightPress\Core\Infrastructure\Privacy\Row;
use DateTimeImmutable;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Privacy\BatchedDelete;
use SightPress\Core\Privacy\Contract\ExpiringPersonalData;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Tenant\TenantRetention;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\ErasureRule;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * What the person asked the AI assistant to do (`assistant_usage`, ADR-0040, ADR-0075): the task, service, model and
 * token counts, never the prompt or the answer. The counts stay for the site's budget when the account is erased;
 * they point at the *Former user* tombstone.
 */
final readonly class AssistantUsageData implements PersonalDataProvider, ExpiringPersonalData
{
    public const string KEY = 'assistant.usage';

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Use of the AI assistant'),
            new Message('To keep to the site’s monthly budget and each person’s limits.'),
            [PersonalDataCategory::Usage],
            LawfulBasis::LegitimateInterests,
            Retention::days(400, 'privacy.retention.assistant_months'),
            ErasureRule::Pseudonymise,
            new Message('Only which task, service and model were used and how much, never what was asked or the answer.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('used_at', 'task', 'provider', 'model', 'input_tokens', 'output_tokens', 'outcome')
            ->from(AssistantUsageStore::TABLE)
            ->where('user_id = :id')
            ->orderBy('used_at')
            ->addOrderBy('id')
            ->setParameter('id', $user->userId)
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'used_at' => Row::date($row['used_at']),
            'task' => $row['task'],
            'service' => $row['provider'],
            'model' => $row['model'],
            'input_tokens' => Row::int($row['input_tokens']),
            'output_tokens' => Row::int($row['output_tokens']),
            'outcome' => $row['outcome'],
        ], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        // The rows keep the account id, which is the tombstone once the account is erased: nothing else in them
        // says who it was.
        return new ErasureResult(pseudonymised: Row::int($this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(AssistantUsageStore::TABLE)
            ->where('user_id = :id')
            ->setParameter('id', $user->userId)
            ->fetchOne()));
    }

    public function purgeExpired(RetentionPolicy $retention, DateTimeImmutable $now): array
    {
        $before = $retention->cutOff(TenantRetention::ASSISTANT, $now);

        return ['assistant use records' => BatchedDelete::run($this->connection, AssistantUsageStore::TABLE, 'id', static fn (QueryBuilder $query): QueryBuilder => $query
            ->where('used_at < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE))];
    }

}
