<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageRevision;
use SightPress\Core\Page\Entity\PageTranslation;

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
