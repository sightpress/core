<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Search\Http;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Search\Http\SearchHandler;
use SightPress\Core\Search\SearchIndex;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(SearchHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class SearchHandlerTest extends TestCase
{
    private KernelSite $site;

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testShowsResultsInEachStateAndLanguage(): void
    {
        $this->site = new KernelSite('search_handler', locales: ['en-GB', 'pt-PT']);
        $this->publish('Opening hours', 'hours', 'When the library is open.');
        $this->publish('Members room', 'members', 'Quiet desks for members.', public: false);
        Services::get($this->site->container(), SearchIndex::class)->reindex();
        $browser = new Browser($this->site->kernel, 'https://example.org');

        $found = $browser->request('GET', '/search?q=open&page=1');
        self::assertSame([200, 'noindex, follow', 'private, no-cache', 'en-GB'], [$found->getStatusCode(), $found->getHeaderLine('X-Robots-Tag'), $found->getHeaderLine('Cache-Control'), $found->getHeaderLine('Content-Language')]);
        self::assertStringContainsString('<h1>1 result for “open”</h1>', (string) $found->getBody());
        self::assertStringContainsString('<h1>1 result for “open”</h1>', (string) $browser->request('GET', '/search?q=open&page=0')->getBody());
        self::assertStringContainsString('<h1>1 result for “open”</h1>', (string) $browser->request('GET', '/search?q=open&page=5000')->getBody());
        self::assertStringContainsString('<h1>No results for “quiet”</h1>', (string) $browser->request('GET', '/search?q=quiet')->getBody(), 'members only');
        self::assertStringContainsString('<h1>Pesquisar</h1>', (string) $browser->request('GET', '/pt-pt/search')->getBody());
        self::assertSame('https://example.org/search?q=open', $browser->request('GET', '/en-gb/search?q=open')->getHeaderLine('Location'));

        $handler = Services::get($this->site->container(), SearchHandler::class);
        $member = new ServerRequest('GET', 'https://example.org/search?q=quiet')
            ->withQueryParams(['q' => 'quiet'])
            ->withAttribute(Actor::class, new Actor(7, []))
            ->withAttribute(ViewerPreferences::class, new ViewerPreferences(timezone: 'America/New_York'));
        self::assertStringContainsString('<a href="/members">Members room</a>', (string) $handler->handle($member)->getBody());
        $this->expectException(HttpNotFoundException::class);
        $handler->handle(new ServerRequest('GET', 'https://example.org/fr/search'));
    }

    public function testASiteInOneLanguageHasNoLanguageSwitcherAndRefusesTooManySearches(): void
    {
        $this->site = new KernelSite('search_handler_one');
        $this->publish('Opening hours', 'hours', 'When the library is open.');
        Services::get($this->site->container(), SearchIndex::class)->reindex();
        $browser = new Browser($this->site->kernel, 'https://example.org');

        self::assertStringNotContainsString('language-switcher', (string) $browser->request('GET', '/search?q=open')->getBody());
        self::assertStringNotContainsString('To search in another language', (string) $browser->request('GET', '/search?q=zebra')->getBody());
        for ($search = 2; $search <= 30; ++$search) {
            $browser->request('GET', '/search?q=open');
        }
        $busy = $browser->request('GET', '/search?q=open');
        self::assertSame(429, $busy->getStatusCode());
        self::assertGreaterThan(0, (int) $busy->getHeaderLine('Retry-After'));
        self::assertStringContainsString('<h1>Too many searches</h1>', (string) $busy->getBody());
    }

    private function publish(string $title, string $slug, string $summary, bool $public = true): void
    {
        $page = Services::get($this->site->container(), PageEditor::class)->create('en-GB', new PageContent($title, $slug, $summary));
        $page->changeState(PageState::Published, new DateTimeImmutable());
        $page->setPublic($public, new DateTimeImmutable());
        Services::get($this->site->container(), PageRepository::class)->flush();
    }
}
