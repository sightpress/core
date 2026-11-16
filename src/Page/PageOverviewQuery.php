<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use SightPress\Core\Page\Contract\PageChange;
use SightPress\Core\Page\Contract\PageOverview;
use SightPress\Core\Page\Contract\PageSummary;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\Site;

/**
 * The dashboard's page lists. Each list takes two queries whatever its length: one for the newest rows (limited in
 * the database), one for those pages with their translations.
 */
final readonly class PageOverviewQuery implements PageOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Site $site,
    ) {
    }

    public function draftsBy(int $authorId, int $limit): array
    {
        return $this->summaries($this->newest($limit)
            ->where('p.state = :state')
            ->andWhere('p.authorId = :author')
            ->setParameter('state', PageState::Draft)
            ->setParameter('author', $authorId));
    }

    public function awaitingReview(int $limit): array
    {
        return $this->summaries($this->newest($limit)
            ->where('p.state = :state')
            ->setParameter('state', PageState::InReview));
    }

    public function recentChanges(int $limit): array
    {
        /** @var list<array{id: int|string, number: int|string, savedBy: int|string|null, createdAt: \DateTimeImmutable, page: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('r.id', 'r.number', 'r.savedBy', 'r.createdAt', 'IDENTITY(r.page) AS page')
            ->from(PageRevision::class, 'r')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
        $pages = $this->load(array_map(static fn (array $row): int => (int) $row['page'], $rows));

        return array_map(
            fn (array $row): PageChange => new PageChange(
                $this->summary($pages[(int) $row['page']]),
                (int) $row['number'],
                $row['savedBy'] === null ? null : (int) $row['savedBy'],
                $row['createdAt'],
            ),
            $rows,
        );
    }

    private function newest(int $limit): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from(Page::class, 'p')
            ->orderBy('p.updatedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit);
    }

    /**
     * @return list<PageSummary> in the order of the query
     */
    private function summaries(QueryBuilder $query): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $query->getQuery()->getArrayResult();
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $pages = $this->load($ids);

        return array_map(fn (int $id): PageSummary => $this->summary($pages[$id]), $ids);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Page> by id
     */
    private function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<Page> $pages */
        $pages = $this->entityManager->createQueryBuilder()
            ->select('p', 't')
            ->from(Page::class, 'p')
            ->leftJoin('p.translations', 't')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', array_values(array_unique($ids)))
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($pages as $page) {
            $byId[(int) $page->id()] = $page;
        }

        return $byId;
    }

    private function summary(Page $page): PageSummary
    {
        $translation = $page->translation($this->site->defaultLocale) ?? (array_values($page->translations())[0] ?? null);

        return new PageSummary(
            (int) $page->id(),
            $translation?->locale() ?? $this->site->defaultLocale,
            $translation?->title() ?? 'Untitled page',
            $page->state(),
            $page->authorId(),
            $page->updatedAt(),
        );
    }
}
