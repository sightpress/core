<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;

/**
 * The pages a `page_list` block shows, in one query: only pages everyone may read (published and public, on a public
 * site), so a members-only page never appears, whoever is reading and wherever the list goes (HTML, Markdown,
 * `llms.txt`, JSON-LD). The page the list is on and the home page are left out.
 */
final readonly class PageListQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageAddresses $addresses,
        private SiteSettings $settings,
    ) {
    }

    /**
     * The pages under the page at an address (the top-level pages for null), in their order in the tree.
     *
     * @return list<array{title: string, url: string, summary: ?string}>|null null when there is no page at that address
     */
    public function children(?string $parentUrl, string $locale, int $limit, ?string $currentUrl = null): ?array
    {
        $parent = null;
        if ($parentUrl !== null) {
            $parent = $this->addresses->find($parentUrl)?->page();
            if ($parent === null) {
                return null;
            }
        }
        $query = $this->query($locale)->orderBy('p.position')->addOrderBy('p.id');
        if ($parent === null) {
            $query->andWhere('p.parent IS NULL');
        } else {
            $query->andWhere('p.parent = :parent')->setParameter('parent', $parent);
        }

        return $this->items($query->getQuery()->getResult(), $limit, $currentUrl);
    }

    /**
     * The pages changed most recently.
     *
     * @return list<array{title: string, url: string, summary: ?string}>
     */
    public function latest(string $locale, int $limit, ?string $currentUrl = null): array
    {
        return $this->items($this->query($locale)->orderBy('t.updatedAt', 'DESC')->addOrderBy('p.id', 'DESC')->getQuery()->getResult(), $limit, $currentUrl);
    }

    private function query(string $locale): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(PageTranslation::class, 't')
            ->join('t.page', 'p')
            ->where('t.locale = :locale')
            ->setParameter('locale', $locale);
    }

    /**
     * @param mixed $translations
     *
     * @return list<array{title: string, url: string, summary: ?string}>
     */
    private function items(mixed $translations, int $limit, ?string $currentUrl): array
    {
        $home = $this->addresses->home();
        $items = [];
        foreach (is_array($translations) ? $translations : [] as $translation) {
            if (!$translation instanceof PageTranslation || count($items) >= $limit) {
                continue;
            }
            $page = $translation->page();
            $url = $this->addresses->url($translation);
            if (
                !$translation->isPublished() || !SiteVisibility::isOpenToEveryone($page->isPublic(), $this->settings)
                || $page === $home || $url === $currentUrl
            ) {
                continue;
            }
            $items[] = ['title' => $translation->title(), 'url' => $url, 'summary' => $translation->summary()];
        }

        return $items;
    }
}
