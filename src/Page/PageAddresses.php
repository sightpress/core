<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\Site;

/**
 * Where a site's pages live (ADR-0007): pages in the default locale at `/<path>`, the others at
 * `/<lower-case locale>/<path>`. The home page is at `/` (or `/pt-pt` for its Portuguese version), never at its own
 * path. The home page is the one in the site setting `page.home`; without it, the first page at the top of the tree.
 */
final class PageAddresses
{
    public const string HOME_SETTING = 'page.home';

    /** @var array{?Page}|null the home page once looked up */
    private ?array $home = null;

    public function __construct(
        private readonly Site $site,
        private readonly PageRepository $pages,
        private readonly SiteSettings $settings,
    ) {
    }

    /**
     * The locale and the page path (without slashes at either end, `''` for the home page) of an address on the
     * site. A query or fragment is ignored.
     *
     * @return array{string, string}
     */
    public function split(string $url): array
    {
        $path = trim((string) parse_url('http://x/' . ltrim($url, '/'), PHP_URL_PATH), '/');
        $segments = explode('/', $path, 2);
        foreach ($this->site->locales as $locale) {
            if ($locale !== $this->site->defaultLocale && strtolower($locale) === $segments[0]) {
                return [$locale, $segments[1] ?? ''];
            }
        }

        return [$this->site->defaultLocale, $path];
    }

    /**
     * The page translation at an address, whatever its state; the home page's for `/`.
     */
    public function find(string $url): ?PageTranslation
    {
        [$locale, $path] = $this->split($url);

        return $this->findIn($locale, $path);
    }

    /**
     * The page translation at a page path (without slashes at either end, `''` for the home page) in one locale,
     * whatever its state.
     */
    public function findIn(string $locale, string $path): ?PageTranslation
    {
        if ($path === '') {
            return $this->home()?->translation($locale);
        }

        return $this->pages->findByPath($locale, rawurldecode($path));
    }

    /**
     * The address part of a locale: `''` for the site's default locale, otherwise `/` and the lower-case locale.
     */
    public function prefixOf(string $locale): string
    {
        return $this->prefix($locale);
    }

    /**
     * The address of a page translation on the site: `/about/team`, `/pt-pt/sobre`, `/` for the home page.
     */
    public function url(PageTranslation $translation): string
    {
        if ($this->isHome($translation->page())) {
            return $this->homeUrl($translation->locale());
        }

        return $this->prefix($translation->locale()) . '/' . $translation->path();
    }

    public function homeUrl(string $locale): string
    {
        $prefix = $this->prefix($locale);

        return $prefix === '' ? '/' : $prefix;
    }

    public function home(): ?Page
    {
        if ($this->home === null) {
            $id = $this->settings->get(self::HOME_SETTING);
            $home = $id !== null && ctype_digit($id) ? $this->pages->find((int) $id) : null;
            $this->home = [$home ?? ($this->pages->children(null)[0] ?? null)];
        }

        return $this->home[0];
    }

    public function isHome(Page $page): bool
    {
        return $page->id() !== null && $this->home()?->id() === $page->id();
    }

    /**
     * Makes a saved page the home page.
     */
    public function setHome(Page $page): void
    {
        $this->settings->set(self::HOME_SETTING, (string) ($page->id() ?? throw new PageException('Save the page before making it the home page.')));
        $this->home = [$page];
    }

    private function prefix(string $locale): string
    {
        return $locale === $this->site->defaultLocale ? '' : '/' . strtolower($locale);
    }
}
