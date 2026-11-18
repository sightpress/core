<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Seo;

use DateTimeImmutable;
use DOMDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * C-6.7 acceptance through the kernel: titles and descriptions with fallbacks, one canonical address per page
 * (trailing slash and other domains move permanently), the robots rules, the schema.org graph, a sitemap valid
 * against the sitemaps.org schema with only the pages search engines may index (refreshed when a page is
 * published), robots.txt, the visible "last updated" date and `site:seo:check`.
 */
#[CoversNothing]
final class SeoTest extends TestCase
{
    private ?KernelSite $site = null;

    /** @var array<string, Page> */
    private array $pages = [];

    protected function tearDown(): void
    {
        $this->site?->remove();
    }

    public function testEveryPageHasItsTitleDescriptionCanonicalAndGraph(): void
    {
        $browser = $this->browser('production');

        $about = $browser->request('GET', '/about');
        $html = (string) $about->getBody();
        self::assertStringContainsString('<title>About us - Demo</title>', $html);
        self::assertStringContainsString('<meta name="description" content="We look after the books, the music and the view of the harbour, and we run reading groups for every age, from babies to grandparents, every single week of the…">', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/about">', $html);
        self::assertSame('<https://example.org/about>; rel="canonical"', $about->getHeaderLine('Link'));
        self::assertStringNotContainsString('name="robots"', $html, 'a public page in production asks nothing');
        self::assertFalse($about->hasHeader('X-Robots-Tag'));
        self::assertStringContainsString('<meta property="og:url" content="https://example.org/about">', $html);
        self::assertMatchesRegularExpression('#<p class="page-updated">Last updated <time datetime="\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ">\d{1,2} \w+ \d{4}</time></p>#', $html);

        $team = (string) $browser->request('GET', '/about/team')->getBody();
        self::assertStringContainsString('<title>Meet the team at Riverside</title>', $team, 'the title for search engines');
        self::assertStringContainsString('<meta name="description" content="The people behind the library.">', $team);
        $graph = $this->graph($team);
        self::assertSame(['WebPage', 'BreadcrumbList'], array_column($graph, '@type'));
        self::assertSame('https://example.org/#website', self::dig($graph, 0, 'isPartOf', '@id'));
        self::assertSame(['Home', 'About us', 'Our team'], array_column((array) self::dig($graph, 1, 'itemListElement'), 'name'));
        self::assertSame('https://example.org/about/team', self::dig($graph, 1, 'itemListElement', 2, 'item'));

        $home = (string) $browser->request('GET', '/')->getBody();
        self::assertStringContainsString('<title>Welcome to Demo</title>', $home, 'no repeated site name');
        self::assertSame(['WebPage'], array_column($this->graph($home), '@type'));

        foreach (['/about/' => 'https://example.org/about', '/about/team//' => 'https://example.org/about/team'] as $path => $location) {
            $moved = $browser->request('GET', $path);
            self::assertSame([301, $location], [$moved->getStatusCode(), $moved->getHeaderLine('Location')], $path);
        }
        $alias = new Browser($this->site->kernel ?? self::fail(), 'https://www.example.org')->request('GET', '/about?ref=1');
        self::assertSame([301, 'https://example.org/about?ref=1'], [$alias->getStatusCode(), $alias->getHeaderLine('Location')]);

        $copy = $browser->request('GET', '/copy');
        self::assertStringContainsString('<link rel="canonical" href="https://elsewhere.example/original">', (string) $copy->getBody());
        $hidden = $browser->request('GET', '/hidden');
        self::assertSame('noindex', $hidden->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex">', (string) $hidden->getBody());
    }

    public function testTheSitemapListsOnlyIndexablePagesAndIsValid(): void
    {
        $browser = $this->browser('production');

        $response = $browser->request('GET', '/sitemap.xml');
        self::assertSame('application/xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $index = (string) $response->getBody();
        $document = new DOMDocument();
        $document->loadXML($index);
        self::assertTrue($document->schemaValidate(dirname(__DIR__, 2) . '/Support/siteindex.xsd'), $index);
        self::assertSame(['https://example.org/sitemap-en-gb.xml', 'https://example.org/sitemap-pt-pt.xml'], $this->locations($index));

        $byLocale = [];
        foreach (['en-gb', 'pt-pt'] as $locale) {
            $xml = (string) $browser->request('GET', '/sitemap-' . $locale . '.xml')->getBody();
            $document = new DOMDocument();
            $document->loadXML($xml);
            self::assertTrue($document->schemaValidate(dirname(__DIR__, 2) . '/Support/sitemap-with-alternates.xsd'), $xml);
            $byLocale[$locale] = $xml;
        }
        self::assertSame(['https://example.org/', 'https://example.org/about', 'https://example.org/about/team'], $this->locations($byLocale['en-gb']));
        self::assertSame(['https://example.org/pt-pt'], $this->locations($byLocale['pt-pt']));
        foreach ($byLocale as $xml) {
            self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="pt-PT" href="https://example.org/pt-pt"/>', $xml);
            self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="https://example.org/"/>', $xml);
        }
        self::assertMatchesRegularExpression('#<lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00</lastmod>#', $byLocale['en-gb']);
        self::assertSame(404, $browser->request('GET', '/sitemap-fr-fr.xml')->getStatusCode());

        $container = $this->site?->container() ?? self::fail();
        $this->pages['plans']->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($container, PageEditor::class)->save($this->pages['plans'], 'en-GB', new PageContent('Plans', 'plans', 'What comes next.'));
        self::assertContains('https://example.org/plans', $this->locations((string) $browser->request('GET', '/sitemap-en-gb.xml')->getBody()), 'the cached sitemap was refreshed');

        $robots = (string) $browser->request('GET', '/robots.txt')->getBody();
        self::assertStringContainsString("User-agent: *\nDisallow: /admin\n", $robots);
        self::assertStringContainsString("\nSitemap: https://example.org/sitemap.xml\n", $robots);
        self::assertStringContainsString('Sitemap: https://example.org/sitemap.xml', (string) new Browser($this->site->kernel ?? self::fail(), 'https://www.example.org')->request('GET', '/robots.txt')->getBody(), 'always the first domain');
    }

    public function testOutsideProductionEverythingIsKeptOutOfSearch(): void
    {
        $browser = $this->browser('staging');

        $about = $browser->request('GET', '/about');
        self::assertSame('noindex, nofollow', $about->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $about->getBody());
        self::assertSame("# This install is not in production: search engines are asked to stay away.\nUser-agent: *\nDisallow: /\n", (string) $browser->request('GET', '/robots.txt')->getBody());
    }

    public function testTheCheckReportsWhatSearchEnginesWillFind(): void
    {
        $this->browser('test');
        $container = $this->site?->container() ?? self::fail();
        Services::get($container, PageEditor::class)->create('en-GB', new PageContent('About us', 'about-us-again'))->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($container, PageRepository::class)->flush();

        $application = $this->site->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run(['command' => 'site:seo:check', 'slug' => 'demo'], ['capture_stderr_separately' => true]);

        self::assertSame(1, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Note: this install is not in production', $display);
        self::assertStringContainsString('Warning: /about has no description or summary; search engines get its first words.', $display);
        self::assertStringContainsString('Left out of search: /hidden (noindex).', $display);
        self::assertStringContainsString('Left out of search: /members (members only).', $display);
        self::assertStringContainsString('Left out of search: /copy (a copy of https://elsewhere.example/original).', $display);
        self::assertStringContainsString('5 pages in the sitemap; 1 problem to fix.', $display);
        self::assertStringContainsString('These pages have the same title, so search engines can\'t tell them apart: /about, /about-us-again.', $tester->getErrorOutput());
    }

    private function browser(string $environment): Browser
    {
        $this->site = new KernelSite('seo', ['example.org', 'www.example.org'], $environment, ['en-GB', 'pt-PT']);
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $text = static fn (string $html): array => [['type' => 'rich_text', 'version' => 1, 'data' => ['html' => $html]]];
        $this->pages['home'] = $editor->create('en-GB', new PageContent('Welcome to Demo', 'home', 'The library by the river.'));
        $editor->save($this->pages['home'], 'pt-PT', new PageContent('Bem-vindo', 'inicio', 'A biblioteca junto ao rio.'));
        $editor->publishTranslation($this->pages['home'], 'pt-PT', true);
        $this->pages['about'] = $editor->create('en-GB', new PageContent('About us', 'about', null, null, $text('<p>We look after the books, the music and the view of the harbour, and we run reading groups for every age, from babies to grandparents, every single week of the year.</p>')));
        $this->pages['team'] = $editor->create('en-GB', new PageContent('Our team', 'team', null, 'The people behind the library.', [], null, 'Meet the team at Riverside'), $this->pages['about']);
        $this->pages['hidden'] = $editor->create('en-GB', new PageContent('Hidden', 'hidden', 'Not for search.', null, [], null, null, true));
        $this->pages['copy'] = $editor->create('en-GB', new PageContent('Copy', 'copy', 'A copy.', null, [], null, null, false, 'https://elsewhere.example/original'));
        $this->pages['members'] = $editor->create('en-GB', new PageContent('Members', 'members', 'For members.'));
        $this->pages['members']->setPublic(false, new DateTimeImmutable());
        $this->pages['plans'] = $editor->create('en-GB', new PageContent('Plans', 'plans'));
        foreach (['home', 'about', 'team', 'hidden', 'copy', 'members'] as $key) {
            $this->pages[$key]->changeState(PageState::Published, new DateTimeImmutable());
        }
        Services::get($container, PageRepository::class)->flush();

        return new Browser($this->site->kernel, 'https://example.org');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graph(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.+?)</script>#', $html, $scripts);
        foreach ($scripts[1] as $json) {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertSame('https://schema.org', $data['@context'] ?? null);
            if (self::dig($data, '@graph', 0, '@type') === 'WebPage') {
                /** @var list<array<string, mixed>> */
                return $data['@graph'];
            }
        }
        self::fail('No WebPage node.');
    }

    private static function dig(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function locations(string $xml): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);

        return $matches[1];
    }
}
