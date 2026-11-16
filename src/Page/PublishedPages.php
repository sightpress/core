<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Contract\PublicPage;
use SightPress\Core\Page\Contract\PublicPages;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\Site;

/**
 * Pages in the default locale are at `/<path>`, the others at `/<lower-case locale>/<path>` (ADR-0007).
 */
final readonly class PublishedPages implements PublicPages
{
    public function __construct(
        private PageRepository $pages,
        private Site $site,
    ) {
    }

    public function findByUrl(string $url): ?PublicPage
    {
        $path = trim((string) parse_url('http://x' . (str_starts_with($url, '/') ? $url : '/' . $url), PHP_URL_PATH), '/');
        $locale = $this->site->defaultLocale;
        $segments = explode('/', $path, 2);
        foreach ($this->site->locales as $candidate) {
            if ($candidate !== $this->site->defaultLocale && strtolower($candidate) === $segments[0]) {
                $locale = $candidate;
                $path = $segments[1] ?? '';
            }
        }
        $translation = $path === '' ? null : $this->pages->findByPath($locale, $path);
        if ($translation === null) {
            return null;
        }
        $page = $translation->page();
        if ($page->state() !== PageState::Published || !$page->isPublic()) {
            return null;
        }
        $prefix = $locale === $this->site->defaultLocale ? '' : '/' . strtolower($locale);

        return new PublicPage((int) $page->id(), $locale, $prefix . '/' . $translation->path(), $translation->title(), $translation->blocks());
    }
}
