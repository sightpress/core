<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Seo;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageLinkQuery;
use SightPress\Core\Seo\RobotsPolicy;
use SightPress\Core\Seo\SearchConsoles;
use SightPress\Core\Seo\SeoHead;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PageFixture;
use SightPress\Core\Tests\Support\TestProject;

#[CoversClass(SeoHead::class)]
#[CoversClass(SearchConsoles::class)]
#[UsesNamespace('SightPress\Core')]
final class SeoHeadTest extends TestCase
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

    public function testAddsTheTitleDescriptionCanonicalAlternatesAndGraph(): void
    {
        $home = $this->pages->published('Welcome');
        $about = $this->pages->published('About');
        $this->pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre'));
        $team = $this->pages->published('Team', [], $about);
        $blocks = new BlockFixture([], $this->pages->database);
        $picture = $blocks->addMedia();
        $image = [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 'The harbour']]];

        $event = $this->event((int) $team->id(), '/about/team', 'Team', [(int) $about->id()], blocks: $image, html: '<p>The people <em>behind</em> the library.</p>');
        $this->head('production')($event);

        self::assertSame('Team - Demo', $event->variables()['document_title']);
        self::assertSame('The people behind the library.', $event->metaDescription());
        self::assertNull($event->robots());
        $head = $event->head();
        self::assertStringContainsString('<meta name="description" content="The people behind the library.">', $head);
        self::assertStringContainsString('<link rel="canonical" href="https://demo.example/about/team">', $head);
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://demo.example/about/team.md">', $head);
        self::assertStringNotContainsString('hreflang', $head, 'only written in one language');
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.+)</script>#', $head, $json));
        $graph = json_decode($json[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($graph);
        self::assertSame(['WebPage', 'BreadcrumbList', 'ImageObject'], array_column((array) $graph['@graph'], '@type'));
        self::assertSame([
            '@type' => 'WebPage',
            '@id' => 'https://demo.example/about/team#webpage',
            'url' => 'https://demo.example/about/team',
            'name' => 'Team - Demo',
            'description' => 'The people behind the library.',
            'inLanguage' => 'en-GB',
            'isPartOf' => ['@id' => 'https://demo.example/#website'],
            'dateModified' => '2026-11-16T09:30:00+00:00',
            'breadcrumb' => ['@id' => 'https://demo.example/about/team#breadcrumb'],
        ], self::dig($graph, '@graph', 0));
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://demo.example/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'About', 'item' => 'https://demo.example/about'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Team', 'item' => 'https://demo.example/about/team'],
        ], self::dig($graph, '@graph', 1, 'itemListElement'));

        $portuguese = $this->event((int) $about->id(), '/pt-pt/sobre', 'Sobre', [], 'pt-PT', [(int) $home->id(), 999]);
        $this->head('production')($portuguese);
        $head = $portuguese->head();
        self::assertStringContainsString('<link rel="alternate" hreflang="en-GB" href="https://demo.example/about">', $head);
        self::assertStringContainsString('<link rel="alternate" hreflang="pt-PT" href="https://demo.example/pt-pt/sobre">', $head);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://demo.example/about">', $head);
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" hreflang="en-GB" href="https://demo.example/about.md">', $head);
        self::assertStringContainsString('<meta property="og:locale:alternate" content="en_GB">', $head);
        self::assertStringNotContainsString('content="pt_PT"', $head, 'the page\'s own locale is not an alternate');
        self::assertStringContainsString('"itemListElement":[{"@type":"ListItem","position":1,"name":"Home","item":"https://demo.example/pt-pt"}', $head);
        self::assertStringNotContainsString('name="description"', $head, 'nothing to describe it with');
    }

    public function testATranslationSearchEnginesShouldntListIsNoAlternate(): void
    {
        $this->pages->published('Welcome');
        $about = $this->pages->published('About');
        $this->pages->translated($about, 'pt-PT', new PageContent('Sobre', 'sobre', null, null, [], null, null, true));

        $event = $this->event((int) $about->id(), '/about', 'About', []);
        $this->head('production')($event);

        self::assertStringNotContainsString('hreflang', $event->head(), 'the sitemap leaves the noindex translation out too');
    }

    public function testFrontPagesCopiesAndPagesOutOfSearch(): void
    {
        $home = $this->pages->published('Welcome to Demo');
        $this->pages->translated($home, 'pt-PT', new PageContent('Bem-vindo', 'inicio'));

        $settings = $this->pages->database->settings();
        $settings->set('seo.verify_google', 'abc-123_XYZ');
        $settings->set('seo.verify_bing', 'not a code!');
        $front = $this->event((int) $home->id(), '/', 'Welcome to Demo', [], home: true);
        $this->head('test')($front);
        self::assertStringContainsString('<meta name="google-site-verification" content="abc-123_XYZ">', $front->head());
        self::assertStringNotContainsString('msvalidate.01', $front->head(), 'a code that doesn’t look right is left out');
        self::assertSame('Welcome to Demo', $front->variables()['document_title']);
        self::assertSame('noindex, nofollow', $front->robots());
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $front->head());
        self::assertStringContainsString('"@graph":[{"@type":"WebPage"', $front->head());
        self::assertStringNotContainsString('BreadcrumbList', $front->head());
        self::assertStringContainsString('hreflang="pt-PT" href="https://demo.example/pt-pt"', $front->head());

        $copy = $this->event((int) $home->id(), '/', 'Welcome to Demo', [], home: true, canonical: 'https://elsewhere.example/page', noindex: true, seoTitle: 'Demo library');
        $this->head('production')($copy);
        self::assertSame('Demo library', $copy->variables()['document_title']);
        self::assertSame('noindex', $copy->robots());
        self::assertStringContainsString('<link rel="canonical" href="https://elsewhere.example/page">', $copy->head());
        self::assertStringNotContainsString('hreflang', $copy->head(), 'a copy has no alternates of its own');
        self::assertStringNotContainsString('text/markdown', $copy->head());
        self::assertStringContainsString('<link rel="alternate" type="text/markdown" href="https://demo.example/index.md">', $front->head());
    }

    /**
     * @param list<int>                                                           $ancestors
     * @param list<int>                                                           $extraAncestors
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    private function event(int $id, string $url, string $title, array $ancestors, string $locale = 'en-GB', array $extraAncestors = [], bool $home = false, array $blocks = [], string $html = '', ?string $canonical = null, bool $noindex = false, ?string $seoTitle = null): PublicPageRendering
    {
        return new PublicPageRendering(new ServerRequest('GET', $url), $id, $locale, $url, 'https://demo.example/', $title, null, null, [...$ancestors, ...$extraAncestors], $home, true, new DateTimeImmutable('2026-11-16 09:30:00 UTC'), null, $seoTitle, $noindex, $canonical, $blocks, $html);
    }

    private static function dig(mixed $data, int|string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $data;
    }

    private function head(string $environment): SeoHead
    {
        $blocks = new BlockFixture([], $this->pages->database);

        return new SeoHead(
            $this->pages->site,
            new RobotsPolicy(new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $environment])),
            new PageLinkQuery($this->pages->database->entityManager, $this->pages->addresses()),
            $blocks->renderer(),
            $blocks->contexts($this->pages->site),
            new SearchConsoles($this->pages->database->settings()),
        );
    }
}
