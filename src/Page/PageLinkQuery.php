<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Page\Contract\PageLink;
use SightPress\Core\Page\Contract\PageLinks;
use SightPress\Core\Page\Entity\PageTranslation;

/**
 * Pages as links, from one query of their translations in a locale.
 */
final readonly class PageLinkQuery implements PageLinks
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageAddresses $addresses,
    ) {
    }

    public function find(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }
        $links = [];
        foreach ($this->translations($locale, array_values(array_unique($ids))) as $translation) {
            $link = $this->link($translation);
            $links[$link->id] = $link;
        }

        return $links;
    }

    public function all(string $locale): array
    {
        $children = [];
        foreach ($this->translations($locale, null) as $translation) {
            $children[$translation->page()->parent()?->id() ?? 0][] = $translation;
        }
        $links = [];
        $walk = function (int $parent) use (&$walk, &$links, $children): void {
            $siblings = $children[$parent] ?? [];
            usort($siblings, static fn (PageTranslation $a, PageTranslation $b): int => [$a->page()->position(), $a->page()->id()] <=> [$b->page()->position(), $b->page()->id()]);
            foreach ($siblings as $translation) {
                $links[] = $this->link($translation);
                $walk((int) $translation->page()->id());
            }
        };
        $walk(0);

        return $links;
    }

    /**
     * @param list<int>|null $ids null for every page
     *
     * @return list<PageTranslation>
     */
    private function translations(string $locale, ?array $ids): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(PageTranslation::class, 't')
            ->join('t.page', 'p')
            ->where('t.locale = :locale')
            ->setParameter('locale', $locale);
        if ($ids !== null) {
            $query->andWhere('p.id IN (:ids)')->setParameter('ids', $ids);
        }

        /** @var list<PageTranslation> */
        return $query->getQuery()->getResult();
    }

    private function link(PageTranslation $translation): PageLink
    {
        $page = $translation->page();

        return new PageLink(
            (int) $page->id(),
            $translation->title(),
            $this->addresses->url($translation),
            $page->depth(),
            $translation->isPublished() && $page->isPublic(),
            $translation->isPublished() && $page->isPublic() && !$translation->isNoindex() && $translation->canonicalUrl() === null,
        );
    }
}
