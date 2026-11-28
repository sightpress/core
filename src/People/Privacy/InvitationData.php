<?php

declare(strict_types=1);

namespace SightPress\Core\People\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Infrastructure\Privacy\Row;
use DateTimeImmutable;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use SightPress\Core\Infrastructure\Privacy\BatchedDelete;
use SightPress\Core\Privacy\Contract\ExpiringPersonalData;
use SightPress\Core\Privacy\Contract\RetentionPolicy;
use SightPress\Core\Tenant\TenantRetention;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * Invitations (`invitations`, ADR-0075), found by the email address they were sent to. Invitations the person sent
 * to others are the other people's data; they keep pointing at the person's account, which becomes the *Former
 * user* tombstone when it is erased.
 */
final readonly class InvitationData implements PersonalDataProvider, ExpiringPersonalData
{
    public const string KEY = 'people.invitations';

    private const string TABLE = 'invitations';

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Invitations'),
            new Message('To let someone who was invited join the site with the role they were given.'),
            [PersonalDataCategory::Identity, PersonalDataCategory::Contact],
            LawfulBasis::LegitimateInterests,
            Retention::days(30, 'privacy.retention.invitation_days'),
            notes: new Message('An invitation that wasn’t accepted is deleted 30 days after it expired.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('email', 'role', 'first_name', 'last_name', 'created_at', 'expires_at', 'accepted_at')
            ->from(self::TABLE)
            ->where('LOWER(email) = :email')
            ->orderBy('created_at')
            ->setParameter('email', mb_strtolower($user->email))
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'email' => $row['email'],
            'role' => $row['role'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'sent_at' => Row::date($row['created_at']),
            'expires_at' => Row::date($row['expires_at']),
            'accepted_at' => Row::date($row['accepted_at']),
        ], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        return new ErasureResult(deleted: (int) $this->connection->createQueryBuilder()
            ->delete(self::TABLE)
            ->where('LOWER(email) = :email')
            ->setParameter('email', mb_strtolower($user->email))
            ->executeStatement());
    }

    public function purgeExpired(RetentionPolicy $retention, DateTimeImmutable $now): array
    {
        $before = $retention->cutOff(TenantRetention::INVITATIONS, $now);

        return ['invitations' => BatchedDelete::run($this->connection, self::TABLE, 'id', static fn (QueryBuilder $query): QueryBuilder => $query
            ->where('(accepted_at IS NULL AND expires_at < :before) OR accepted_at < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE))];
    }

}
