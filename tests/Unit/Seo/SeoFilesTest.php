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
use SightPress\Core\Page\Contract\MarkdownPages;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageMarkdown;
use SightPress\Core\Seo\AiCrawlerPolicy;
use SightPress\Core\Tests\Support\BlockFixture;
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
#[CoversClass(AiCrawlerPolicy::class)]
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
        $this->pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
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
        $this->pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $cache = new ArrayAdapter();
        $files = $this->files('production', $cache);

        $sitemap = $files->sitemap('https://demo.example/');
        self::assertStringContainsString('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $sitemap);
        self::assertStringContainsString("<loc>https://demo.example/sitemap-en-gb.xml</loc>\n  <lastmod>2026-11-14T12:00:00+00:00</lastmod>", $sitemap);
        self::assertStringContainsString('<loc>https://demo.example/sitemap-pt-pt.xml</loc>', $sitemap);
        $english = (string) $files->localeSitemap('https://demo.example', 'en-GB');
        $portuguese = (string) $files->localeSitemap('https://demo.example', 'PT-pt');
        self::assertStringContainsString('<loc>https://demo.example/</loc>', $english);
        self::assertStringNotContainsString('<loc>https://demo.example/pt-pt/sobre</loc>', $english);
        self::assertStringContainsString('<loc>https://demo.example/pt-pt/sobre</loc>', $portuguese);
        foreach ([$english, $portuguese] as $one) {
            self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="pt-PT" href="https://demo.example/pt-pt/sobre"/>', $one);
            self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="en-GB" href="https://demo.example/about"/>', $one);
            self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="https://demo.example/about"/>', $one);
        }
        self::assertSame(1, substr_count($english, 'hreflang="en-GB"'), 'Home has no alternates');
        self::assertNull($files->localeSitemap('https://demo.example', 'fr-FR'));
        $robots = $files->robots('https://demo.example');
        self::assertSame("User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /login\nDisallow: /logout\nDisallow: /contact-form\nAllow: /\n\nSitemap: https://demo.example/sitemap.xml\n", $robots);

        $this->pages->published('Later');
        self::assertSame($sitemap, $files->sitemap('https://demo.example'), 'cached');
        new SeoCachePurger($files)(new \stdClass());
        self::assertStringContainsString('/later</loc>', (string) $files->localeSitemap('https://demo.example', 'en-GB'));
        self::assertStringContainsString('Disallow: /', $this->files('staging', new ArrayAdapter())->robots('https://demo.example'));
    }

    public function testListsThePagesForAiReadersInLlmsTxt(): void
    {
        $home = $this->pages->published('Home');
        $this->pages->editor()->save($home, 'en-GB', new PageContent('Home', 'home', null, "The library\n by the river."));
        $about = $this->pages->published('About [us]');
        $this->pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre', 'Quem somos.'));
        $files = $this->files('production', new ArrayAdapter());

        self::assertSame(
            "# Demo\n\n> The library by the river.\n\nEvery page of this website is also available as Markdown: add `.md` to its address (`/index.md` for the home page).\n\n"
            . "## Pages in British English (en-GB)\n\n- [Home](https://demo.example/index.md): The library by the river.\n- [About \\[us\\]](https://demo.example/about-us.md)\n\n"
            . "## Pages in European Portuguese (pt-PT)\n\n- [Sobre](https://demo.example/pt-pt/sobre.md): Quem somos.\n\n"
            . "## Optional\n\n- [Full text of every page](https://demo.example/llms-full.txt): all the pages above in one Markdown file\n",
            $files->llms('https://demo.example'),
        );
        $full = $files->llmsFull('https://demo.example');
        self::assertStringStartsWith("# Demo\n\n> The library by the river.\n\n# Home\n", $full);
        self::assertSame(3, substr_count($full, 'Source: <'));

        $this->pages->database->settings()->set(\SightPress\Core\Site\Contract\SiteProfile::DESCRIPTION, "A library\nfor everyone.");
        self::assertStringStartsWith("# Demo\n\n> A library for everyone.\n", $this->files('production', new ArrayAdapter())->llms('https://demo.example'), 'the site’s own description comes first');
    }

    public function testTheFullTextStopsBeforeItGetsTooLarge(): void
    {
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->pages->published($title);
        }
        $large = new class () implements MarkdownPages {
            public function document(string $locale, string $title, ?string $summary, array $blocks, string $canonicalUrl, DateTimeImmutable $updatedAt, string $baseUrl): string
            {
                return '# ' . $title . "\n\n" . str_repeat('x', 200_000) . "\n";
            }
        };

        $full = $this->files('production', new ArrayAdapter(), $large)->llmsFull('https://demo.example');

        self::assertStringStartsWith("# Demo\n\n> The website of Demo.\n\n# One\n", $full);
        self::assertStringContainsString("\n# Two\n", $full);
        self::assertStringNotContainsString('# Three', $full);
        self::assertStringEndsWith("\n---\n\n> 1 more page was left out to keep this file under 500 KB. Every page is listed in https://demo.example/llms.txt and has a Markdown version.\n", $full);
        self::assertLessThan(SeoFiles::FULL_TEXT_MAX_BYTES, strlen($full));
        $this->pages->published('Four');
        self::assertStringContainsString('2 more pages were left out', $this->files('production', new ArrayAdapter(), $large)->llmsFull('https://demo.example'));
    }

    public function testTheAiCrawlerPolicyKeepsCrawlersOut(): void
    {
        $settings = $this->pages->database->settings();
        $policy = $this->aiCrawlers();
        self::assertSame([AiCrawlerPolicy::ALLOW_ALL, []], [$policy->current(), $policy->blocked()]);
        $settings->set(AiCrawlerPolicy::SETTING, 'nonsense');
        self::assertSame(AiCrawlerPolicy::ALLOW_ALL, $this->aiCrawlers()->current());
        $settings->set(AiCrawlerPolicy::SETTING, AiCrawlerPolicy::SEARCH_ONLY);
        self::assertSame(AiCrawlerPolicy::crawlers()['training'], $this->aiCrawlers()->blocked());
        self::assertContains('GPTBot', AiCrawlerPolicy::crawlers()['training']);
        self::assertContains('OAI-SearchBot', AiCrawlerPolicy::crawlers()['search']);
        $cache = new ArrayAdapter();
        self::assertStringContainsString("User-agent: ClaudeBot\n", $this->files('production', $cache)->robots('https://demo.example'));
        $settings->set(AiCrawlerPolicy::SETTING, AiCrawlerPolicy::BLOCK_ALL);
        self::assertCount(count(AiCrawlerPolicy::crawlers()['training']) + count(AiCrawlerPolicy::crawlers()['search']), $this->aiCrawlers()->blocked());
        self::assertStringContainsString("User-agent: Claude-SearchBot\n", $this->files('production', $cache)->robots('https://demo.example'), 'a new policy is not served from the cache');
    }

    public function testServesThemForTheFirstDomain(): void
    {
        $this->pages->published('Home');
        $handler = new SeoFileHandler($this->files('production', new ArrayAdapter()), $this->pages->site, new Psr17Factory());

        $sitemap = $handler->handle(new ServerRequest('GET', 'https://www.demo.example:8443/sitemap.xml'));
        self::assertSame('application/xml; charset=utf-8', $sitemap->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=3600', $sitemap->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<loc>https://demo.example:8443/sitemap-en-gb.xml</loc>', (string) $sitemap->getBody());
        $english = $handler->handle(new ServerRequest('GET', 'https://demo.example:8443/sitemap-en-gb.xml'));
        self::assertSame('application/xml; charset=utf-8', $english->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<loc>https://demo.example:8443/</loc>', (string) $english->getBody());
        foreach (['/sitemap-fr-fr.xml', '/sitemap-x.xml'] as $missing) {
            try {
                $handler->handle(new ServerRequest('GET', 'https://demo.example' . $missing));
                self::fail($missing);
            } catch (\Slim\Exception\HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $single = new SeoFileHandler($this->files('production', new ArrayAdapter(), site: PageFixture::site(['en-GB'])), PageFixture::site(['en-GB']), new Psr17Factory());
        self::assertStringContainsString('<urlset', (string) $single->handle(new ServerRequest('GET', 'https://demo.example/sitemap.xml'))->getBody());
        $robots = $handler->handle(new ServerRequest('GET', 'https://demo.example/robots.txt'));
        self::assertSame('text/plain; charset=utf-8', $robots->getHeaderLine('Content-Type'));
        self::assertStringEndsWith("Sitemap: https://demo.example/sitemap.xml\n", (string) $robots->getBody());
        self::assertStringStartsWith('# Demo', (string) $handler->handle(new ServerRequest('GET', 'https://demo.example/llms.txt'))->getBody());
        $full = $handler->handle(new ServerRequest('GET', 'https://demo.example/llms-full.txt'));
        self::assertSame('text/plain; charset=utf-8', $full->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Source: <https://demo.example/>', (string) $full->getBody());
    }

    public function testKeepsItsCacheInTheTenantsCacheFolderByDefault(): void
    {
        $this->pages->published('Home');
        $config = new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'production']);
        $files = new SeoFiles($this->index(), $this->policy('production'), $this->pages->site, $config, $this->aiCrawlers(), $this->documents(), $this->pages->database->settings());

        self::assertStringContainsString('<loc>https://demo.example/</loc>', (string) $files->localeSitemap('https://demo.example', 'en-GB'));
        self::assertDirectoryExists($config->cacheDir . '/seo-' . $this->pages->site->id);
        $files->purge();
    }

    private function index(): PageIndexQuery
    {
        return new PageIndexQuery($this->pages->database->entityManager, $this->pages->addresses(), $this->pages->site, new BlockFixture([], $this->pages->database)->texts($this->pages->site));
    }

    private function policy(string $environment): RobotsPolicy
    {
        return new RobotsPolicy(new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment]));
    }

    private function files(string $environment, ArrayAdapter $cache, ?MarkdownPages $documents = null, ?\SightPress\Core\Tenant\Site $site = null): SeoFiles
    {
        return new SeoFiles($this->index(), $this->policy($environment), $site ?? $this->pages->site, new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment]), $this->aiCrawlers(), $documents ?? $this->documents(), $this->pages->database->settings(), $cache);
    }

    private function aiCrawlers(): AiCrawlerPolicy
    {
        return new AiCrawlerPolicy($this->pages->database->settings());
    }

    private function documents(): PageMarkdown
    {
        $blocks = new BlockFixture([], $this->pages->database);

        return new PageMarkdown($blocks->contexts($this->pages->site), $blocks->renderer());
    }
}
