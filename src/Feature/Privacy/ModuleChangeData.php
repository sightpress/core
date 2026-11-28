<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Privacy;

use Doctrine\DBAL\Connection;
use SightPress\Core\Infrastructure\Privacy\Row;
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
 * Who last turned each feature on or off (`site_module.changed_by`, F-2, ADR-0075). The choice stays when the
 * account is erased, under the *Former user* tombstone.
 */
final readonly class ModuleChangeData implements PersonalDataProvider
{
    public const string KEY = 'feature.changes';

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Features you turned on or off'),
            new Message('To show who last changed each feature of the site.'),
            [PersonalDataCategory::Account],
            LawfulBasis::LegitimateInterests,
            Retention::whileAccountExists(),
            ErasureRule::Pseudonymise,
            new Message('The site keeps the choice when an account is deleted, under “Former user”.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('module_id', 'enabled', 'changed_at')
            ->from('site_module')
            ->where('changed_by = :id')
            ->orderBy('module_id')
            ->setParameter('id', $user->userId)
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'feature' => $row['module_id'],
            'on' => (bool) $row['enabled'],
            'changed_at' => Row::date($row['changed_at']),
        ], $rows);
    }

    public function erase(UserRef $user): ErasureResult
    {
        return new ErasureResult(pseudonymised: Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM site_module WHERE changed_by = ?', [$user->userId])));
    }
}
