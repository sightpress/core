<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use Doctrine\ORM\EntityManagerInterface;
use SightPress\Core\Page\Contract\IndexedPage;
use SightPress\Core\Page\Contract\PageIndex;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\Site;

/**
 * The published pages, from one query of their translations.
 */
final readonly class PageIndexQuery implements PageIndex
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PageAddresses $addresses,
        private Site $site,
    ) {
    }

    public function published(): array
    {
        /** @var list<PageTranslation> $translations */
        $translations = $this->entityManager->createQueryBuilder()
            ->select('t', 'p')
            ->from(PageTranslation::class, 't')
            ->join('t.page', 'p')
            ->where('p.state = :state')
            ->setParameter('state', PageState::Published)
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($translations as $translation) {
            $ids[(int) $translation->page()->id()] = true;
        }
        // A published page under one that isn't published yet is still listed, at the top.
        $children = [];
        foreach ($translations as $translation) {
            $page = $translation->page();
            $parent = $page->parent()?->id() ?? 0;
            $children[isset($ids[$parent]) ? $parent : 0][(int) $page->id()][$translation->locale()] = $translation;
        }
        $entries = [];
        $walk = function (int $parent) use (&$walk, &$entries, $children): void {
            $pages = $children[$parent] ?? [];
            uksort($pages, static fn (int $a, int $b): int => [reset($pages[$a])->page()->position(), $a] <=> [reset($pages[$b])->page()->position(), $b]);
            foreach ($pages as $id => $locales) {
                uksort($locales, fn (string $a, string $b): int => [$a !== $this->site->defaultLocale, $a] <=> [$b !== $this->site->defaultLocale, $b]);
                foreach ($locales as $translation) {
                    $entries[] = $this->entry($translation);
                }
                $walk($id);
            }
        };
        $walk(0);

        return $entries;
    }

    private function entry(PageTranslation $translation): IndexedPage
    {
        $page = $translation->page();

        return new IndexedPage(
            (int) $page->id(),
            $translation->locale(),
            $this->addresses->url($translation),
            $translation->title(),
            $translation->seoTitle(),
            $translation->summary(),
            $translation->description(),
            $page->isPublic(),
            $translation->isNoindex(),
            $translation->canonicalUrl(),
            $page->depth(),
            $translation->updatedAt(),
            $translation->blocks(),
            $this->addresses->isHome($page),
        );
    }
}
