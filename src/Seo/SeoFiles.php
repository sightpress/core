<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use DateTimeZone;
use Psr\Cache\CacheItemPoolInterface;
use SightPress\Core\Page\Contract\IndexedPage;
use SightPress\Core\Page\Contract\PageIndex;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use XMLWriter;

/**
 * A site's `sitemap.xml` and `robots.txt`, kept in a cache of their own until a page changes ({@see purge()}).
 *
 * The sitemap lists every page search engines may index (published, public, not `noindex`, not a copy of
 * another page), in every language, with when it last changed and, on sites with more than one language, its other
 * languages as `xhtml:link` alternates. `robots.txt` keeps crawlers out of the admin, sign-in and account pages
 * and points them to the sitemap; on an install that isn't in production it keeps them out of everything.
 */
final class SeoFiles
{
    /** Paths crawlers have no business in. */
    public const array PRIVATE_PATHS = ['/admin', '/account', '/login', '/logout', '/password/', '/invite/', '/contact-form'];

    private ?CacheItemPoolInterface $cache;

    public function __construct(
        private readonly PageIndex $pages,
        private readonly RobotsPolicy $robots,
        private readonly Site $site,
        private readonly TenantConfig $config,
        ?CacheItemPoolInterface $cache = null,
    ) {
        $this->cache = $cache;
    }

    public function sitemap(string $baseUrl): string
    {
        return $this->cached('sitemap', $baseUrl, fn (string $base): string => $this->buildSitemap($base));
    }

    public function robots(string $baseUrl): string
    {
        return $this->cached('robots', $baseUrl, fn (string $base): string => $this->buildRobots($base));
    }

    /**
     * The pages search engines may index.
     *
     * @return list<IndexedPage>
     */
    public function indexable(): array
    {
        return array_values(array_filter($this->published(), static fn (IndexedPage $page): bool => $page->isIndexable()));
    }

    /**
     * Every published page, indexable or not.
     *
     * @return list<IndexedPage>
     */
    public function published(): array
    {
        return $this->pages->published();
    }

    /**
     * Forgets the cached files, after a page changed.
     */
    public function purge(): void
    {
        $this->cache()->clear();
    }

    private function buildSitemap(string $base): string
    {
        $pages = $this->indexable();
        $languages = [];
        foreach ($pages as $page) {
            $languages[$page->id][$page->locale] = $base . $page->url;
        }
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        foreach ($pages as $page) {
            $xml->startElement('url');
            $xml->writeElement('loc', $base . $page->url);
            $xml->writeElement('lastmod', $page->updatedAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_W3C));
            if (count($languages[$page->id]) > 1) {
                foreach ($languages[$page->id] as $locale => $href) {
                    $xml->startElement('xhtml:link');
                    $xml->writeAttribute('rel', 'alternate');
                    $xml->writeAttribute('hreflang', $locale);
                    $xml->writeAttribute('href', $href);
                    $xml->endElement();
                }
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function buildRobots(string $base): string
    {
        if (!$this->robots->isProduction()) {
            return "# This install is not in production: search engines are asked to stay away.\nUser-agent: *\nDisallow: /\n";
        }
        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Allow: /';
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param callable(string): string $build
     */
    private function cached(string $name, string $baseUrl, callable $build): string
    {
        $base = rtrim($baseUrl, '/');
        $item = $this->cache()->getItem($name . '.' . hash('xxh128', $base));
        if ($item->isHit() && is_string($item->get())) {
            return $item->get();
        }
        $contents = $build($base);
        $this->cache()->save($item->set($contents));

        return $contents;
    }

    private function cache(): CacheItemPoolInterface
    {
        return $this->cache ??= new FilesystemAdapter('seo-' . $this->site->id, 0, $this->config->cacheDir);
    }
}
