<?php

declare(strict_types=1);

namespace SightPress\Core\Rbac\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Module\Privacy\ErasureResult;
use SightPress\Module\Privacy\LawfulBasis;
use SightPress\Module\Privacy\PersonalDataCategory;
use SightPress\Module\Privacy\PersonalDataProvider;
use SightPress\Module\Privacy\PersonalDataSet;
use SightPress\Module\Privacy\Retention;
use SightPress\Module\Privacy\UserRef;
use SightPress\Module\Text\Message;

/**
 * The roles a person has on the site (`user_role`, ADR-0075).
 */
final readonly class RoleData implements PersonalDataProvider
{
    public const string KEY = 'rbac.roles';

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Your roles'),
            new Message('To decide what you may do on the site.'),
            [PersonalDataCategory::Account],
            LawfulBasis::Contract,
            Retention::whileAccountExists(),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('r.name', 'r.label')
            ->from('user_role', 'ur')
            ->innerJoin('ur', 'roles', 'r', 'r.id = ur.role_id')
            ->where('ur.user_id = :id')
            ->orderBy('r.name')
            ->setParameter('id', $user->userId)
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => ['role' => $row['name'], 'label' => $row['label']], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        return new ErasureResult(deleted: (int) $this->connection->createQueryBuilder()
            ->delete('user_role')
            ->where('user_id = :id')
            ->setParameter('id', $user->userId)
            ->executeStatement());
    }
}
