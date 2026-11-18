<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Rbac\Contract\PageState;

/**
 * The site's pages, in the site's own database. Lists come with their translations in the same query.
 */
final readonly class PageRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(int $id): ?Page
    {
        return $this->entityManager->find(Page::class, $id);
    }

    /**
     * The translation at an address in a locale, with its page.
     *
     * @param string $path without the leading slash and locale prefix, for example `about/team`
     */
    public function findByPath(string $locale, string $path): ?PageTranslation
    {
        /** @var ?PageTranslation */
        return $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(PageTranslation::class, 't')
            ->join('t.page', 'p')
            ->where('t.locale = :locale')
            ->andWhere('t.path = :path')
            ->setParameter('locale', $locale)
            ->setParameter('path', $path)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whether another page has this title (ignoring case) in the locale.
     */
    public function titleTaken(string $locale, string $title, int $exceptPageId): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(PageTranslation::class, 't')
            ->where('t.locale = :locale')
            ->andWhere('LOWER(t.title) = :title')
            ->andWhere('IDENTITY(t.page) <> :page')
            ->setParameter('locale', $locale)
            ->setParameter('title', mb_strtolower($title))
            ->setParameter('page', $exceptPageId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * The children of a page, or the top-level pages, in their order.
     *
     * @return list<Page>
     */
    public function children(?Page $parent): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p', 't')
            ->from(Page::class, 'p')
            ->leftJoin('p.translations', 't')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC');
        if ($parent === null) {
            $query->where('p.parent IS NULL');
        } else {
            $query->where('p.parent = :parent')->setParameter('parent', $parent);
        }

        /** @var list<Page> */
        return $query->getQuery()->getResult();
    }

    /**
     * Every page with its translations, top-down (parents before their children, siblings in their order).
     *
     * @return list<Page>
     */
    public function all(): array
    {
        /** @var list<Page> */
        return $this->entityManager->createQueryBuilder()
            ->select('p', 't')
            ->from(Page::class, 'p')
            ->leftJoin('p.translations', 't')
            ->orderBy('p.depth', 'ASC')
            ->addOrderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every page below a page, top-down (parents before their children).
     *
     * @return list<Page>
     */
    public function descendants(Page $page): array
    {
        if ($page->treePath() === '') {
            return [];
        }

        /** @var list<Page> */
        return $this->entityManager->createQueryBuilder()
            ->select('p', 't')
            ->from(Page::class, 'p')
            ->leftJoin('p.translations', 't')
            ->where('p.treePath LIKE :prefix')
            ->andWhere('p.id <> :id')
            ->setParameter('prefix', $page->treePath() . '%')
            ->setParameter('id', $page->id())
            ->orderBy('p.depth', 'ASC')
            ->addOrderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PageRevision> newest first
     */
    public function revisions(Page $page): array
    {
        /** @var list<PageRevision> */
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PageRevision::class, 'r')
            ->where('r.page = :page')
            ->setParameter('page', $page)
            ->orderBy('r.number', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function revision(Page $page, int $number): ?PageRevision
    {
        return $this->entityManager->getRepository(PageRevision::class)->findOneBy(['page' => $page, 'number' => $number]);
    }

    /**
     * Approved pages whose publishing time has come, earliest first.
     *
     * @return list<Page>
     */
    public function duePublications(DateTimeImmutable $now): array
    {
        return $this->due('publishAt', PageState::Approved, $now);
    }

    /**
     * Published pages whose end time has come, earliest first.
     *
     * @return list<Page>
     */
    public function dueUnpublications(DateTimeImmutable $now): array
    {
        return $this->due('unpublishAt', PageState::Published, $now);
    }

    /**
     * @param 'publishAt'|'unpublishAt' $field
     *
     * @return list<Page>
     */
    private function due(string $field, PageState $state, DateTimeImmutable $now): array
    {
        /** @var list<Page> */
        return $this->entityManager->createQueryBuilder()
            ->select('p', 't')
            ->from(Page::class, 'p')
            ->leftJoin('p.translations', 't')
            ->where('p.state = :state')
            ->andWhere('p.' . $field . ' <= :now')
            ->setParameter('state', $state)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->orderBy('p.' . $field, 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function lastRevisionNumber(Page $page): int
    {
        $number = $this->entityManager->createQueryBuilder()
            ->select('MAX(r.number)')
            ->from(PageRevision::class, 'r')
            ->where('r.page = :page')
            ->setParameter('page', $page)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($number) ? (int) $number : 0;
    }

    /**
     * Deletes pages for good with their translations and revisions, and forgets them. Pages under others come first
     * in the list (deepest first), so no page is ever left pointing to a deleted parent.
     *
     * @param list<Page> $pages
     */
    public function delete(array $pages): void
    {
        if ($pages === []) {
            return;
        }
        $ids = array_map(static fn (Page $page): int => (int) $page->id(), $pages);
        foreach ($pages as $page) {
            foreach ($this->revisions($page) as $revision) {
                $this->entityManager->detach($revision);
            }
        }
        foreach ([PageRevision::class, PageTranslation::class] as $class) {
            $this->entityManager->createQueryBuilder()
                ->delete($class, 'x')
                ->where('IDENTITY(x.page) IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->execute();
        }
        foreach ($pages as $page) {
            $this->entityManager->createQueryBuilder()
                ->delete(Page::class, 'p')
                ->where('p.id = :id')
                ->setParameter('id', $page->id())
                ->getQuery()
                ->execute();
            foreach ($page->translations() as $translation) {
                $this->entityManager->detach($translation);
            }
            $this->entityManager->detach($page);
        }
    }

    /**
     * Marks a new page or revision to be saved by the next {@see flush()}.
     */
    public function add(Page|PageRevision $entity): void
    {
        $this->entityManager->persist($entity);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Runs the work in one database transaction: everything is saved, or nothing.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        return $this->entityManager->wrapInTransaction(static fn (): mixed => $work());
    }
}
