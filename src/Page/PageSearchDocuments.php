<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\MediaTexts;
use SightPress\Core\Page\Contract\SearchablePages;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Search\SearchDocument;
use SightPress\Module\Search\SearchDocumentProvider;
use SightPress\Module\Search\SearchVisibility;

/**
 * Pages as site search documents (SR-1): one per published translation in the site's languages that editors didn't
 * leave out of site search. The text is the title, the summary and the blocks as plain text (with alt text, captions,
 * transcripts and download names); the breadcrumb is the titles of the published pages above it. Pages for members
 * only, and every page of a members-only site, are {@see SearchVisibility::Members}. Pages left out of search
 * engines (`noindex`) are still found by the site's own search.
 */
final readonly class PageSearchDocuments implements SearchDocumentProvider, SearchablePages
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageRepository $pages,
        private PageAddresses $addresses,
        private BlockRenderer $renderer,
        private BlockContexts $contexts,
        private MediaTexts $texts,
        private Site $site,
        private SiteSettings $settings,
    ) {
    }

    public function source(): string
    {
        return self::SOURCE;
    }

    public function documents(string $locale): iterable
    {
        if (!in_array($locale, $this->site->locales, true)) {
            return;
        }
        $translations = $this->published($locale);
        $this->texts->preload(array_map(static fn (PageTranslation $translation): array => $translation->blocks(), $translations));
        $titles = [];
        foreach ($translations as $translation) {
            $titles[(int) $translation->page()->id()] = $translation->title();
        }
        foreach ($translations as $translation) {
            if (!$translation->isExcludedFromSiteSearch()) {
                yield $this->searchDocument($translation, $titles);
            }
        }
    }

    public function document(string $ref, string $locale): ?SearchDocument
    {
        if (preg_match('/^[1-9]\d{0,17}$/', $ref) !== 1 || !in_array($locale, $this->site->locales, true)) {
            return null;
        }
        $translation = $this->pages->find((int) $ref)?->translation($locale);
        if ($translation === null || !$translation->isPublished() || $translation->isExcludedFromSiteSearch()) {
            return null;
        }
        $titles = [];
        foreach ($this->published($locale, self::ancestorIds($translation)) as $above) {
            $titles[(int) $above->page()->id()] = $above->title();
        }

        return $this->searchDocument($translation, $titles);
    }

    public function withPagesBelow(int $pageId): array
    {
        $page = $this->pages->find($pageId);
        if ($page === null) {
            return [];
        }

        return [$pageId, ...array_map(static fn (Page $below): int => (int) $below->id(), $this->pages->descendants($page))];
    }

    public function usingMedia(int $mediaId): array
    {
        $ids = [];
        foreach ($this->site->locales as $locale) {
            foreach ($this->published($locale) as $translation) {
                if (self::uses($translation->blocks(), $mediaId)) {
                    $ids[(int) $translation->page()->id()] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * The translations visitors can read in a locale, of every page or of some pages.
     *
     * @param list<int>|null $pageIds
     *
     * @return list<PageTranslation>
     */
    private function published(string $locale, ?array $pageIds = null): array
    {
        if ($pageIds === []) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(PageTranslation::class, 't')
            ->join('t.page', 'p')
            ->where('p.state = :state')
            ->andWhere('t.draft = :draft')
            ->andWhere('t.locale = :locale')
            ->setParameter('state', PageState::Published)
            ->setParameter('draft', false)
            ->setParameter('locale', $locale)
            ->orderBy('p.depth', 'ASC')
            ->addOrderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC');
        if ($pageIds !== null) {
            $query->andWhere('p.id IN (:ids)')->setParameter('ids', $pageIds);
        }

        /** @var list<PageTranslation> */
        return $query->getQuery()->getResult();
    }

    /**
     * @param array<int, string> $titles titles of published pages in the translation's locale, by page id
     */
    private function searchDocument(PageTranslation $translation, array $titles): SearchDocument
    {
        $page = $translation->page();
        $locale = $translation->locale();
        $blocks = $this->texts->fill($translation->blocks(), $locale);
        $markdown = $this->renderer->markdown($blocks, $this->contexts->create($locale, 'https://' . ($this->site->domains[0] ?? 'localhost')));
        $breadcrumb = [];
        foreach (self::ancestorIds($translation) as $id) {
            if (isset($titles[$id])) {
                $breadcrumb[] = $titles[$id];
            }
        }

        return new SearchDocument(
            self::SOURCE,
            (string) $page->id(),
            $locale,
            $this->addresses->url($translation),
            $translation->title(),
            $translation->updatedAt(),
            $translation->summary() ?? '',
            SearchText::fromMarkdown($markdown),
            $breadcrumb,
            SiteVisibility::isOpenToEveryone($page->isPublic(), $this->settings) ? SearchVisibility::Public : SearchVisibility::Members,
        );
    }

    /**
     * @return list<int> the pages above, from the top
     */
    private static function ancestorIds(PageTranslation $translation): array
    {
        $page = $translation->page();
        $ids = array_map(intval(...), array_filter(explode('/', $page->treePath()), static fn (string $id): bool => $id !== ''));

        return array_values(array_diff($ids, [(int) $page->id()]));
    }

    /**
     * @param array<array-key, mixed> $blocks
     */
    private static function uses(array $blocks, int $mediaId): bool
    {
        $found = false;
        array_walk_recursive($blocks, static function (mixed $value, int|string $key) use (&$found, $mediaId): void {
            $found = $found || ($key === 'media' && $value === $mediaId);
        });

        return $found;
    }
}
