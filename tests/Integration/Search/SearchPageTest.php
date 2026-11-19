<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * SR-2 acceptance through the kernel: the header's search form on every page, the results page (title and heading
 * with the query and the count, marked extracts, pages of results), what it says when nothing was searched for or
 * found, that queries are only ever text, members-only pages for members, the rate limit, the languages, and that
 * search engines leave it out. With search off, all of it is gone.
 */
#[CoversNothing]
final class SearchPageTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    private Browser $browser;

    protected function setUp(): void
    {
        $this->site = new KernelSite('search_page', environment: 'production', locales: ['en-GB', 'pt-PT']);
        $home = $this->page('Riverside Library', 'home', 'Books, events and a café by the river.');
        $this->page('Opening hours', 'hours', 'When the library is open.', [['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>We open every day by the river, from nine.</p>']]]);
        $members = $this->page('Members room', 'members-room', 'Quiet desks by the river for members.');
        $members->setPublic(false, new DateTimeImmutable());
        foreach (range(1, 11) as $number) {
            $this->page('Talk ' . $number, 'talk-' . $number, 'A talk about the river.');
        }
        $editor = Services::get($this->site->container(), PageEditor::class);
        $editor->save($home, 'pt-PT', new PageContent('Biblioteca do Rio', 'inicio', 'Livros e eventos junto ao rio.'));
        $editor->publishTranslation($home, 'pt-PT', true);
        Services::get($this->site->container(), PageRepository::class)->flush();
        Services::get($this->site->container(), SearchIndex::class)->reindex();
        $this->browser = new Browser($this->site->kernel, 'https://example.org');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testEveryPageHasTheSearchFormAndTheSiteSaysHowToSearchIt(): void
    {
        $home = (string) $this->browser->request('GET', '/')->getBody();
        self::assertStringContainsString('<search class="search-form" aria-labelledby="site-search-label">', $home);
        self::assertStringContainsString('<form method="get" action="/search">', $home);
        self::assertStringContainsString('<label id="site-search-label" for="site-search-q">Search this site</label>', $home);
        self::assertStringContainsString('<button type="submit" class="button">Search</button>', $home);
        self::assertStringContainsString('"potentialAction":{"@type":"SearchAction","target":{"@type":"EntryPoint","urlTemplate":"https://example.org/search?q={search_term_string}"},"query-input":"required name=search_term_string"}', $home);
        self::assertStringContainsString('<form method="get" action="/pt-pt/search">', (string) $this->browser->request('GET', '/pt-pt')->getBody());

        foreach (['/sitemap.xml', '/sitemap-en-gb.xml', '/llms.txt', '/llms-full.txt'] as $file) {
            self::assertStringNotContainsString('/search', (string) $this->browser->request('GET', $file)->getBody(), $file);
        }
    }

    public function testTheResultsSayWhatWasSearchedForAndHowManyThereAre(): void
    {
        $response = $this->browser->request('GET', '/search?q=opening+hours');
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('noindex, follow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        self::assertStringNotContainsString('rel="canonical"', $html);
        self::assertStringNotContainsString('og:title', $html);
        self::assertStringContainsString('<title>1 result for “opening hours” - Demo</title>', $html);
        self::assertStringContainsString('<h1>1 result for “opening hours”</h1>', $html);
        self::assertStringContainsString('<input id="search-q" type="search" name="q" value="opening hours"', $html);
        self::assertStringContainsString('<ol class="search-results" start="1">', $html);
        self::assertStringContainsString('<h2 class="search-result__title"><a href="/hours">Opening hours</a></h2>', $html);
        self::assertStringContainsString('<p class="search-result__extract">When the library is <mark>open</mark>.</p>', $html);
        self::assertStringContainsString('<nav class="breadcrumbs" aria-label="Breadcrumb">', $html);
        self::assertStringContainsString('<a href="/search" aria-current="page">1 result for “opening hours”</a>', $html);

        $river = (string) $this->browser->request('GET', '/search?q=river')->getBody();
        self::assertStringContainsString('<h1>13 results for “river”</h1>', $river, 'not the members room');
        self::assertStringContainsString('<nav class="pagination" aria-label="Search results pages">', $river);
        self::assertStringContainsString('href="/search?q=river&amp;page=2" rel="next"', $river);
        $second = (string) $this->browser->request('GET', '/search?q=river&page=2')->getBody();
        self::assertStringContainsString('<title>13 results for “river” (page 2 of 2) - Demo</title>', $second);
        self::assertStringContainsString('<ol class="search-results" start="11">', $second);
        self::assertStringContainsString('<title>13 results for “river” (page 2 of 2) - Demo</title>', (string) $this->browser->request('GET', '/search?q=river&page=9')->getBody(), 'after the last page: the last one');
        self::assertStringContainsString('<title>13 results for “river” (page 1 of 2) - Demo</title>', (string) $this->browser->request('GET', '/search?q=river&page=x')->getBody());

        $any = (string) $this->browser->request('GET', '/search?q=river+zebra')->getBody();
        self::assertStringContainsString('No page has every word you searched for, so these pages have some of them.', $any);
    }

    public function testSaysWhatToDoWhenNothingWasSearchedForOrFound(): void
    {
        $empty = (string) $this->browser->request('GET', '/search')->getBody();
        self::assertStringContainsString('<h1>Search</h1>', $empty);
        self::assertStringContainsString('Type one or more words, then choose Search.', $empty);
        self::assertStringContainsString('<h2>Tips</h2>', $empty);
        self::assertSame((string) $this->browser->request('GET', '/search?q=+++')->getBody(), $empty);
        self::assertStringContainsString('<h1>Search</h1>', (string) $this->browser->request('GET', '/search?q[]=river')->getBody());

        $none = (string) $this->browser->request('GET', '/search?q=zebra')->getBody();
        self::assertStringContainsString('<h1>No results for “zebra”</h1>', $none);
        self::assertStringContainsString('Check the spelling of each word.', $none);
        self::assertStringContainsString('This searches the pages in British English.', $none);
    }

    public function testQueriesAreOnlyEverText(): void
    {
        foreach (['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>'] as $text) {
            $html = (string) $this->browser->request('GET', '/search?q=' . rawurlencode($text))->getBody();
            self::assertStringNotContainsString('<script>alert', $html);
            self::assertStringNotContainsString('<img src=x', $html);
            self::assertStringContainsString(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5), $html);
        }
    }

    public function testMembersFindMembersPages(): void
    {
        self::assertStringNotContainsString('Members room', (string) $this->browser->request('GET', '/search?q=quiet+desks')->getBody());
        $member = $this->signedIn('max@example.org', 'member');
        $html = (string) $member->request('GET', '/search?q=quiet+desks')->getBody();
        self::assertStringContainsString('<a href="/members-room">Members room</a>', $html);
        self::assertStringStartsWith('private', $member->request('GET', '/search?q=quiet')->getHeaderLine('Cache-Control'));
    }

    public function testSearchesOneLanguageAtATime(): void
    {
        $portuguese = $this->browser->request('GET', '/pt-pt/search?q=livros');
        $html = (string) $portuguese->getBody();
        self::assertSame('pt-PT', $portuguese->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<html lang="pt-PT"', $html);
        self::assertStringContainsString('<a href="/pt-pt">Biblioteca do Rio</a>', $html);
        self::assertStringContainsString('<a href="/search?q=livros" lang="en-GB" hreflang="en-GB">British English</a>', $html);
        self::assertStringContainsString('<a href="/pt-pt/search?q=livros" lang="pt-PT" hreflang="pt-PT" aria-current="page">Português europeu</a>', $html);
        self::assertStringContainsString('<h1>1 resultado para “livros”</h1>', $html, 'in the language searched');
        self::assertStringContainsString('<h1>No results for “livros”</h1>', (string) $this->browser->request('GET', '/search?q=livros')->getBody());

        self::assertSame('https://example.org/search?q=river', $this->browser->request('GET', '/en-gb/search?q=river')->getHeaderLine('Location'));
        self::assertSame(404, $this->browser->request('GET', '/fr/search?q=river')->getStatusCode());
    }

    public function testTooManySearchesAreRefusedForAWhile(): void
    {
        foreach (range(1, 30) as $ignored) {
            self::assertSame(200, $this->browser->request('GET', '/search?q=river')->getStatusCode());
        }
        $busy = $this->browser->request('GET', '/search?q=river');
        self::assertSame(429, $busy->getStatusCode());
        self::assertGreaterThan(0, (int) $busy->getHeaderLine('Retry-After'));
        $html = (string) $busy->getBody();
        self::assertStringContainsString('<h1>Too many searches</h1>', $html);
        self::assertMatchesRegularExpression('/You have searched many times in a short while\. Wait [12] minutes?, then search again\./', $html);
        self::assertSame(200, $this->browser->request('GET', '/search')->getStatusCode(), 'the empty form is not a search');
    }

    public function testWithSearchOffEverythingIsGone(): void
    {
        $tenant = $this->site->kernel->container();
        Services::get($tenant, SiteModules::class)->turnOff($this->site->site(), 'search', null);
        Services::get($tenant, SiteContainerFactory::class)->evict('demo');

        $home = (string) $this->browser->request('GET', '/')->getBody();
        self::assertStringNotContainsString('<search', $home);
        self::assertStringNotContainsString('SearchAction', $home);
        self::assertSame(404, $this->browser->request('GET', '/search?q=river')->getStatusCode());
        self::assertSame(404, $this->browser->request('GET', '/pt-pt/search?q=rio')->getStatusCode());
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    private function page(string $title, string $slug, string $summary, array $blocks = []): Page
    {
        $page = Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent($title, $slug, $summary, null, $blocks));
        $page->changeState(PageState::Published, new DateTimeImmutable());
        Services::get($this->site->container(), PageRepository::class)->flush();

        return $page;
    }

    private function signedIn(string $email, string $role): Browser
    {
        $container = $this->site->container();
        $users = Services::get($container, UserRepository::class);
        $user = new User($email, 'Max', 'Mustermann', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        $users->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? self::fail($role));
        $browser = new Browser($this->site->kernel, 'https://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
