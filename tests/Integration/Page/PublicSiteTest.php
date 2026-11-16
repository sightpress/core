<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;

/**
 * The public site through the whole kernel: the home page at `/`, pages at their address, 404s on the theme's error
 * page, and the other modules' routes still answering next to the catch-all page route.
 */
#[CoversNothing]
final class PublicSiteTest extends TestCase
{
    private KernelSite $site;

    protected function setUp(): void
    {
        $this->site = new KernelSite('public_site');
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $now = new \DateTimeImmutable();
        $home = $editor->create('en-GB', new PageContent('Welcome', 'home', 'The library by the river.', null, [
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Open every day.</p>']],
        ]));
        $about = $editor->create('en-GB', new PageContent('About us', 'about'));
        $editor->create('en-GB', new PageContent('Team'), $about);
        $editor->create('en-GB', new PageContent('Plans'));
        foreach ([$home, $about] as $page) {
            $page->changeState(PageState::Published, $now);
        }
        Services::get($container, PageRepository::class)->flush();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testVisitorsReadPublishedPagesAndNothingElse(): void
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');

        $home = $browser->request('GET', '/');
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('<h1>Welcome</h1>', (string) $home->getBody());
        self::assertStringContainsString('<p>Open every day.</p>', (string) $home->getBody());
        self::assertSame('public, max-age=300', $home->getHeaderLine('Cache-Control'));
        self::assertFalse($home->hasHeader('Set-Cookie'), 'no session for visitors, so shared caches can keep the page');
        self::assertNotSame('', $home->getHeaderLine('Content-Security-Policy'));

        self::assertSame(304, $browser->request('GET', '/', headers: ['If-None-Match' => $home->getHeaderLine('ETag')])->getStatusCode());
        self::assertSame(200, $browser->request('HEAD', '/about')->getStatusCode());
        self::assertStringContainsString('<title>About us - ', (string) $browser->request('GET', '/about')->getBody());
        self::assertSame('https://example.org/about', $browser->request('GET', '/about/')->getHeaderLine('Location'));
        self::assertSame('https://example.org/', $browser->request('GET', '/home')->getHeaderLine('Location'));

        foreach (['/about/team', '/plans', '/nowhere'] as $path) {
            $missing = $browser->request('GET', $path);
            self::assertSame(404, $missing->getStatusCode(), $path);
            self::assertStringContainsString('<h1', (string) $missing->getBody(), 'the accessible error page');
        }
    }

    public function testTheOtherModulesRoutesStillAnswer(): void
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');

        self::assertSame(200, $browser->request('GET', '/assets/tokens.css')->getStatusCode());
        self::assertSame(200, $browser->request('GET', '/login')->getStatusCode());
        self::assertSame(303, $browser->request('GET', '/admin')->getStatusCode());
        self::assertSame(404, $browser->request('GET', '/media/' . str_repeat('a', 32) . '/x.jpg')->getStatusCode());
    }
}
