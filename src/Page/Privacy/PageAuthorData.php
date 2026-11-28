<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Privacy;

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
 * Who wrote, scheduled and saved each page (`pages.author_id`, `pages.scheduled_by`, `page_revisions.saved_by`,
 * ADR-0075). Pages belong to the site (D46): they stay when an account is erased, and bylines, revisions and the
 * admin say *Former user*.
 */
final readonly class PageAuthorData implements PersonalDataProvider
{
    public const string KEY = 'page.authors';

    public function __construct(private Connection $connection)
    {
    }

    public function describe(): PersonalDataSet
    {
        return new PersonalDataSet(
            self::KEY,
            new Message('Pages you wrote or changed'),
            new Message('To show the history of each page and who to ask about it.'),
            [PersonalDataCategory::Account],
            LawfulBasis::LegitimateInterests,
            Retention::whileAccountExists(),
            ErasureRule::Pseudonymise,
            new Message('Pages belong to the site, so they stay when an account is deleted and show “Former user” as who wrote or changed them.'),
        );
    }

    public function export(UserRef $user): iterable
    {
        $pages = $this->connection->createQueryBuilder()
            ->select('p.id', 'p.created_at', 'p.author_id', 'p.scheduled_by', 'MIN(t.title) AS title')
            ->from('pages', 'p')
            ->leftJoin('p', 'page_translations', 't', 't.page_id = p.id')
            ->where('p.author_id = :id OR p.scheduled_by = :id')
            ->groupBy('p.id', 'p.created_at', 'p.author_id', 'p.scheduled_by')
            ->orderBy('p.id')
            ->setParameter('id', $user->userId)
            ->fetchAllAssociative();
        $records = array_map(static fn (array $row): array => [
            'page_id' => Row::int($row['id']),
            'title' => $row['title'],
            'you_wrote_it' => Row::int($row['author_id']) === $user->userId,
            'you_scheduled_it' => Row::int($row['scheduled_by']) === $user->userId,
            'created_at' => Row::date($row['created_at']),
        ], $pages);
        $revisions = $this->connection->createQueryBuilder()
            ->select('page_id', 'COUNT(*) AS saved', 'MAX(created_at) AS last_saved_at')
            ->from('page_revisions')
            ->where('saved_by = :id')
            ->groupBy('page_id')
            ->orderBy('page_id')
            ->setParameter('id', $user->userId)
            ->fetchAllAssociative();
        foreach ($revisions as $row) {
            $records[] = [
                'page_id' => Row::int($row['page_id']),
                'versions_you_saved' => Row::int($row['saved']),
                'last_saved_at' => Row::date($row['last_saved_at']),
            ];
        }

        return $records;
    }

    public function erase(UserRef $user): ErasureResult
    {
        // The ids stay: they point at the account, which is the tombstone once it is erased.
        $pages = Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM pages WHERE author_id = ? OR scheduled_by = ?', [$user->userId, $user->userId]));
        $revisions = Row::int($this->connection->fetchOne('SELECT COUNT(*) FROM page_revisions WHERE saved_by = ?', [$user->userId]));

        return new ErasureResult(pseudonymised: $pages + $revisions);
    }
}
