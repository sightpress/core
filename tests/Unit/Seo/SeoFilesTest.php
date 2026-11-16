<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\IndexedPage;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageIndexQuery;
use SightPress\Core\Seo\Http\SeoFileHandler;
use SightPress\Core\Seo\RobotsPolicy;
use SightPress\Core\Seo\SeoCachePurger;
use SightPress\Core\Seo\SeoFiles;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(SeoFiles::class)]
#[CoversClass(SeoFileHandler::class)]
#[CoversClass(SeoCachePurger::class)]
#[CoversClass(PageIndexQuery::class)]
#[CoversClass(IndexedPage::class)]
#[UsesNamespace('SightPress\Core')]
final class SeoFilesTest extends TestCase
{
    private PageFixture $pages;

    private TestProject $project;

    protected function setUp(): void
    {
        $this->pages = new PageFixture();
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsThePublishedPagesInTreeOrderAndWhichMayBeIndexed(): void
    {
        $about = $this->pages->published('About');
        $this->pages->editor()->save($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $home = $this->pages->published('Home');
        $this->pages->editor()->move($home, null, 0);
        $draft = $this->pages->editor()->create('en-GB', new PageContent('Draft'));
        $this->pages->published('Under the draft', [], $draft);
        $this->pages->published('Team', [], $about);
        $this->pages->published('Hidden', [], null);
        $hidden = $this->pages->repository()->findByPath('en-GB', 'hidden');
        self::assertNotNull($hidden);
        $this->pages->editor()->save($hidden->page(), 'en-GB', new PageContent('Hidden', 'hidden', null, null, [], null, null, true));

        $index = $this->index()->published();

        self::assertSame(
            ['/', '/draft/under-the-draft', '/about', '/pt-pt/sobre', '/about/team', '/hidden'],
            array_map(static fn (IndexedPage $page): string => $page->url, $index),
            'a published page under a draft is listed at the top; each language of a page together, the default first',
        );
        self::assertSame([true, true, true, true, true, false], array_map(static fn (IndexedPage $page): bool => $page->isIndexable(), $index));
        self::assertSame(1, $index[4]->depth);
        $copy = new IndexedPage(1, 'en-GB', '/copy', 'Copy', null, null, null, true, false, 'https://example.org/x', 0, new DateTimeImmutable(), []);
        self::assertFalse($copy->isIndexable());
        self::assertFalse(new IndexedPage(1, 'en-GB', '/m', 'M', null, null, null, false, false, null, 0, new DateTimeImmutable(), [])->isIndexable());
    }

    public function testMakesTheSitemapAndRobotsAndKeepsThemUntilAPageChanges(): void
    {
        $this->pages->published('Home');
        $about = $this->pages->published('About');
        $this->pages->editor()->save($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $cache = new ArrayAdapter();
        $files = $this->files('production', $cache);

        $sitemap = $files->sitemap('https://demo.example/');
        self::assertStringContainsString('<loc>https://demo.example/</loc>', $sitemap);
        self::assertStringContainsString('<lastmod>2026-11-14T12:00:00+00:00</lastmod>', $sitemap);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="pt-PT" href="https://demo.example/pt-pt/sobre"/>', $sitemap);
        self::assertSame(2, substr_count($sitemap, 'hreflang="en-GB"'), 'both languages of About list both; Home has none');
        $robots = $files->robots('https://demo.example');
        self::assertSame("User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /login\nDisallow: /logout\nDisallow: /password/\nDisallow: /invite/\nDisallow: /contact-form\nAllow: /\n\nSitemap: https://demo.example/sitemap.xml\n", $robots);

        $this->pages->published('Later');
        self::assertSame($sitemap, $files->sitemap('https://demo.example'), 'cached');
        new SeoCachePurger($files)(new \stdClass());
        self::assertStringContainsString('/later</loc>', $files->sitemap('https://demo.example'));
        self::assertStringContainsString('Disallow: /', $this->files('staging', new ArrayAdapter())->robots('https://demo.example'));
    }

    public function testServesThemForTheFirstDomain(): void
    {
        $this->pages->published('Home');
        $handler = new SeoFileHandler($this->files('production', new ArrayAdapter()), $this->pages->site, new Psr17Factory());

        $sitemap = $handler->handle(new ServerRequest('GET', 'https://www.demo.example:8443/sitemap.xml'));
        self::assertSame('application/xml; charset=utf-8', $sitemap->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=3600', $sitemap->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<loc>https://demo.example:8443/</loc>', (string) $sitemap->getBody());
        $robots = $handler->handle(new ServerRequest('GET', 'https://demo.example/robots.txt'));
        self::assertSame('text/plain; charset=utf-8', $robots->getHeaderLine('Content-Type'));
        self::assertStringEndsWith("Sitemap: https://demo.example/sitemap.xml\n", (string) $robots->getBody());
    }

    public function testKeepsItsCacheInTheTenantsCacheFolderByDefault(): void
    {
        $this->pages->published('Home');
        $config = new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'production']);
        $files = new SeoFiles($this->index(), $this->policy('production'), $this->pages->site, $config);

        self::assertStringContainsString('<loc>https://demo.example/</loc>', $files->sitemap('https://demo.example'));
        self::assertDirectoryExists($config->cacheDir . '/seo-' . $this->pages->site->id);
        $files->purge();
    }

    private function index(): PageIndexQuery
    {
        return new PageIndexQuery($this->pages->database->entityManager, $this->pages->addresses(), $this->pages->site);
    }

    private function policy(string $environment): RobotsPolicy
    {
        return new RobotsPolicy(new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment]));
    }

    private function files(string $environment, ArrayAdapter $cache): SeoFiles
    {
        return new SeoFiles($this->index(), $this->policy($environment), $this->pages->site, new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment]), $cache);
    }
}
