<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Page\Http\PublicPageHandler;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\PageVoter;
use SightPress\Core\Rbac\VoterAuthorizer;
use SightPress\Core\Tests\Support\ContactFixture;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(PublicPageHandler::class)]
#[CoversClass(\SightPress\Core\Page\Contract\PublicPageRendering::class)]
#[UsesNamespace('SightPress\Core')]
final class PublicPageHandlerTest extends TestCase
{
    private ContactFixture $fixture;

    private \SightPress\Core\Tests\Support\TestProject $project;

    protected function setUp(): void
    {
        $this->fixture = new ContactFixture();
        $this->project = new \SightPress\Core\Tests\Support\TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheHomePageIsAtTheTopOfTheSiteInItsLayoutAndMayBeShared(): void
    {
        $home = $this->fixture->pages->published('Welcome', [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'News & events', 'level' => 2]]]);
        $home->setLayout('home', $this->fixture->pages->clock->now());
        $this->fixture->pages->editor()->save($home, 'en-GB', new PageContent('Welcome', 'welcome', 'All about the library.', null, $home->translation('en-GB')?->blocks() ?? []));

        $response = $this->get('https://demo.example/');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('en-GB', $response->getHeaderLine('Content-Language'));
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        $html = (string) $response->getBody();
        self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $html);
        self::assertStringContainsString('<title>Welcome - Demo</title>', $html);
        self::assertStringContainsString('<div class="hero">', $html, 'the home layout');
        self::assertStringContainsString('<p class="lead">All about the library.</p>', $html);
        self::assertStringContainsString('News &amp; events</h2>', $html);

        $etag = $response->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
        $again = $this->get('https://demo.example/', ['If-None-Match' => 'W/"other", ' . $etag]);
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('', (string) $again->getBody());
        self::assertSame($etag, $again->getHeaderLine('ETag'));
    }

    public function testPagesAreAtTheirAddressInTheirLocaleAndUnknownLayoutsFallBack(): void
    {
        $this->fixture->pages->published('Welcome');
        $about = $this->fixture->pages->published('About us');
        $about->setLayout('gallery', $this->fixture->pages->clock->now());
        $this->fixture->pages->translated($about, 'pt-PT', new PageContent('Sobre nós', 'sobre'));
        $team = $this->fixture->pages->published('Team', [], $about);

        $html = (string) $this->get('https://demo.example/about-us')->getBody();
        $event = end($this->fixture->pages->events);
        self::assertInstanceOf(\SightPress\Core\Page\Contract\PublicPageRendering::class, $event);
        self::assertSame([(int) $about->id(), 'en-GB', '/about-us', 'https://demo.example', 'About us', false, true], [$event->pageId, $event->locale, $event->url, $event->baseUrl, $event->title, $event->home, $event->public]);
        self::assertSame(['https://demo.example/about-us', null, null], [$event->canonicalUrl(), $event->metaDescription(), $event->robots()]);
        self::assertSame([], $event->ancestorIds);
        self::assertStringContainsString('<title>About us - Demo</title>', $html);
        self::assertStringContainsString('<div class="container container--narrow">', $html, 'the default layout');
        self::assertStringContainsString('<title>Team - Demo</title>', (string) $this->get('https://demo.example/about-us/team')->getBody());
        $event = end($this->fixture->pages->events);
        self::assertInstanceOf(\SightPress\Core\Page\Contract\PublicPageRendering::class, $event);
        self::assertSame([(int) $about->id()], $event->ancestorIds);
        $portuguese = $this->get('https://demo.example/pt-pt/sobre');
        self::assertSame('pt-PT', $portuguese->getHeaderLine('Content-Language'));
        self::assertStringContainsString('<html lang="pt-PT" dir="ltr">', (string) $portuguese->getBody());
        self::assertNotNull($team->id());
    }

    public function testModulesAddVariablesAndHeadTagsWhileThePageIsRendered(): void
    {
        $this->fixture->pages->published('Welcome');
        $events = new class () implements \Psr\EventDispatcher\EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                \assert($event instanceof \SightPress\Core\Page\Contract\PublicPageRendering);
                $event->set('main_menu', [['label' => 'Visit us', 'url' => '/visit', 'current' => false, 'children' => []]]);
                $event->addHead('<meta name="test" content="1">');
                $event->addHead('<meta name="test" content="2">');
                $event->setRobots('noindex');
                $event->setCanonicalUrl('https://demo.example/elsewhere');
                $event->setMetaDescription('Changed.');

                return $event;
            }
        };

        $response = $this->handler($events)->handle($this->fixture->auth->request('GET', 'https://demo.example/'));
        $html = (string) $response->getBody();
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertSame('<https://demo.example/elsewhere>; rel="canonical"', $response->getHeaderLine('Link'));

        self::assertStringContainsString("<meta name=\"test\" content=\"1\">\n<meta name=\"test\" content=\"2\">", $html);
        self::assertStringContainsString('<li><a href="/visit">Visit us</a></li>', $html);
    }

    public function testEveryPageIsThereAsMarkdown(): void
    {
        $home = $this->fixture->pages->published('Welcome', [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'News', 'level' => 2]]]);
        $this->fixture->pages->translated($home, 'pt-PT', new PageContent('Bem-vindo', 'inicio'));
        $hidden = $this->fixture->pages->published('Hidden');
        $this->fixture->pages->editor()->save($hidden, 'en-GB', new PageContent('Hidden', 'hidden', null, null, [], null, null, true, 'https://elsewhere.example/hidden'));

        $markdown = $this->get('https://demo.example/index.md');
        self::assertSame('text/markdown; charset=utf-8', $markdown->getHeaderLine('Content-Type'));
        self::assertSame("# Welcome\n\n## News\n\n---\n\nSource: <https://demo.example/>  \nLast updated: 2026-11-14\n", (string) $markdown->getBody());
        self::assertSame(['Accept', '<https://demo.example/>; rel="canonical"', 'public, max-age=300'], [$markdown->getHeaderLine('Vary'), $markdown->getHeaderLine('Link'), $markdown->getHeaderLine('Cache-Control')]);
        self::assertFalse($markdown->hasHeader('X-Robots-Tag'));
        self::assertSame(304, $this->get('https://demo.example/index.md', ['If-None-Match' => $markdown->getHeaderLine('ETag')])->getStatusCode());
        self::assertStringStartsWith('# Bem-vindo', (string) $this->get('https://demo.example/pt-pt/index.md')->getBody());
        self::assertStringStartsWith('# Welcome', (string) $this->get('https://demo.example/', ['Accept' => 'text/markdown'])->getBody());
        self::assertSame('private, no-cache', $this->get('https://demo.example/index.md', [], new Actor(3))->getHeaderLine('Cache-Control'));

        $copy = $this->get('https://demo.example/hidden.md');
        self::assertSame('noindex', $copy->getHeaderLine('X-Robots-Tag'));
        self::assertSame('<https://elsewhere.example/hidden>; rel="canonical"', $copy->getHeaderLine('Link'));
        foreach (['https://demo.example/welcome.md' => 'https://demo.example/index.md', 'https://demo.example/pt-pt.md' => 'https://demo.example/pt-pt/index.md', 'https://demo.example/hidden/index.md' => 'https://demo.example/hidden.md'] as $from => $to) {
            self::assertSame($to, $this->get($from)->getHeaderLine('Location'), $from);
        }
    }

    public function testMarkdownOutsideProductionAsksSearchEnginesToStayAway(): void
    {
        $this->fixture->pages->published('Welcome');

        $response = $this->handler(config: 'staging')->handle($this->fixture->auth->request('GET', 'https://demo.example/index.md'));

        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    public function testAddressesThatAreNotThePagesOwnMovePermanently(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->pages->published('About');

        foreach ([
            'https://demo.example/about/?x=1' => 'https://demo.example/about?x=1',
            'https://demo.example/welcome' => 'https://demo.example/',
            'https://demo.example/about//' => 'https://demo.example/about',
            'https://WWW.Demo.example/about?x=1' => 'https://demo.example/about?x=1',
            'http://www.demo.example:8080/' => 'http://demo.example:8080/',
        ] as $from => $to) {
            $response = $this->get($from);
            self::assertSame(301, $response->getStatusCode(), $from);
            self::assertSame($to, $response->getHeaderLine('Location'), $from);
        }
    }

    public function testDraftsAndMissingPagesAreNotFoundAndMembersOnlyPagesAskVisitorsToSignIn(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->pages->editor()->create('en-GB', new PageContent('Draft'));
        $members = $this->fixture->pages->published('Members');
        $members->setPublic(false, $this->fixture->pages->clock->now());
        $this->fixture->pages->repository()->flush();

        foreach (['/draft', '/missing', '/fr/members', '/pt-pt/draft', '/pt-pt/missing'] as $path) {
            try {
                $this->get('https://demo.example' . $path);
                self::fail('Expected a 404 for ' . $path);
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['/members' => '/login?return=%2Fmembers', '/members.md' => '/login?return=%2Fmembers.md', '/members?x=1' => '/login?return=%2Fmembers%3Fx%3D1'] as $path => $location) {
            $visitor = $this->get('https://demo.example' . $path);
            self::assertSame([302, $location, 'no-store', 'noindex, nofollow', ''], [$visitor->getStatusCode(), $visitor->getHeaderLine('Location'), $visitor->getHeaderLine('Cache-Control'), $visitor->getHeaderLine('X-Robots-Tag'), (string) $visitor->getBody()], $path);
        }

        $untranslated = $this->get('https://demo.example/pt-pt');
        self::assertSame([302, 'https://demo.example/', 'en-GB'], [$untranslated->getStatusCode(), $untranslated->getHeaderLine('Location'), $untranslated->getHeaderLine('Content-Language')], 'the home page falls back to the default language');

        $signedIn = $this->get('https://demo.example/members', [], new Actor(7));
        self::assertSame(200, $signedIn->getStatusCode());
        self::assertSame('private, no-cache', $signedIn->getHeaderLine('Cache-Control'));
        $event = end($this->fixture->pages->events);
        self::assertInstanceOf(\SightPress\Core\Page\Contract\PublicPageRendering::class, $event);
        self::assertFalse($event->public, 'never indexable');
        $markdown = $this->get('https://demo.example/members.md', [], new Actor(7));
        self::assertSame([200, 'noindex'], [$markdown->getStatusCode(), $markdown->getHeaderLine('X-Robots-Tag')]);
    }

    public function testOnAMembersOnlySiteNoPageIsIndexable(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->pages->published('About us');
        $this->fixture->pages->database->settings()->set(\SightPress\Core\Site\Contract\SiteVisibility::SETTING, 'members');

        $page = $this->get('https://demo.example/about-us', [], new Actor(7));
        $event = end($this->fixture->pages->events);
        self::assertInstanceOf(\SightPress\Core\Page\Contract\PublicPageRendering::class, $event);
        self::assertFalse($event->public);
        self::assertSame(200, $page->getStatusCode());
        self::assertSame('noindex', $this->get('https://demo.example/about-us.md', [], new Actor(7))->getHeaderLine('X-Robots-Tag'));
    }

    public function testAPageWithAFormIsPrivate(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->page();

        $response = $this->get('https://demo.example/contact');

        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Send message', (string) $response->getBody());
    }

    public function testADraftTranslationIsOnlyThereForThePeopleWhoCanChangeThePage(): void
    {
        $this->fixture->pages->published('Welcome');
        $about = $this->fixture->pages->published('About us');
        $this->fixture->pages->editor()->translate($about, 'pt-PT', 'en-GB');

        $visitor = $this->get('https://demo.example/pt-pt/about-us');
        self::assertSame([302, 'https://demo.example/about-us'], [$visitor->getStatusCode(), $visitor->getHeaderLine('Location')], 'visitors are sent to the published language');
        $editor = new Actor(3, ['page.edit.any']);
        $preview = $this->get('https://demo.example/pt-pt/about-us', [], $editor);
        self::assertSame(['200', 'private, no-cache'], [(string) $preview->getStatusCode(), $preview->getHeaderLine('Cache-Control')]);
    }

    public function testAPageNotYetInTheAskedLanguageIsATemporaryRedirectToTheClosestOne(): void
    {
        $this->fixture->pages->published('Welcome');
        $about = $this->fixture->pages->published('About us');
        $this->fixture->pages->translated($about, 'pt-PT', new PageContent('Sobre nós', 'sobre'));
        $team = $this->fixture->pages->published('Team', [], $about);

        $markdown = $this->get('https://demo.example/pt-pt/about-us/team.md');
        self::assertSame('https://demo.example/about-us/team.md', $markdown->getHeaderLine('Location'), 'the address in another language finds the page');
        self::assertSame(['<https://demo.example/about-us/team>; rel="alternate"; hreflang="en-GB"', '<https://demo.example/about-us/team>; rel="alternate"; hreflang="x-default"'], $markdown->getHeader('Link'));
        $fallback = $this->get('https://demo.example/pt-pt/about-us?x=1');
        self::assertSame([302, 'https://demo.example/pt-pt/sobre', 'pt-PT'], [$fallback->getStatusCode(), $fallback->getHeaderLine('Location'), $fallback->getHeaderLine('Content-Language')]);
        self::assertSame([
            '<https://demo.example/about-us>; rel="alternate"; hreflang="en-GB"',
            '<https://demo.example/pt-pt/sobre>; rel="alternate"; hreflang="pt-PT"',
            '<https://demo.example/about-us>; rel="alternate"; hreflang="x-default"',
        ], $fallback->getHeader('Link'));
        self::assertSame('public, max-age=300', $fallback->getHeaderLine('Cache-Control'));
        self::assertSame('private, no-cache', $this->get('https://demo.example/pt-pt/about-us', [], new Actor(3))->getHeaderLine('Cache-Control'));
        unset($team);
    }

    public function testTheLanguagePartIsWrittenInLowerCaseAndNotAtAllForTheDefault(): void
    {
        $this->fixture->pages->published('Welcome');
        $about = $this->fixture->pages->published('About us');
        $this->fixture->pages->translated($about, 'pt-PT', new PageContent('Sobre nós', 'sobre'));

        foreach (['/PT-PT/sobre' => '/pt-pt/sobre', '/Pt-pt' => '/pt-pt', '/en-gb/about-us' => '/about-us', '/EN-GB' => '/'] as $from => $to) {
            $response = $this->get('https://demo.example' . $from);
            self::assertSame([301, 'https://demo.example' . $to], [$response->getStatusCode(), $response->getHeaderLine('Location')], $from);
        }
    }

    public function testTheHomePageIsInTheVisitorsLanguageOnlyAtTheTopAddress(): void
    {
        $home = $this->fixture->pages->published('Welcome');
        $about = $this->fixture->pages->published('About us');
        $this->fixture->pages->translated($home, 'pt-PT', new PageContent('Bem-vindo', 'inicio'));

        $sent = $this->get('https://demo.example/', ['Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.5']);
        self::assertSame([302, 'https://demo.example/pt-pt', 'Accept-Language, Cookie', 'private, no-cache'], [$sent->getStatusCode(), $sent->getHeaderLine('Location'), $sent->getHeaderLine('Vary'), $sent->getHeaderLine('Cache-Control')]);
        $english = $this->get('https://demo.example/', ['Accept-Language' => 'en-US,en;q=0.9']);
        self::assertSame([200, 'Accept, Accept-Language'], [$english->getStatusCode(), $english->getHeaderLine('Vary')]);
        self::assertSame(200, $this->get('https://demo.example/', ['Accept-Language' => 'pt-PT', 'Referer' => 'https://demo.example/pt-pt'])->getStatusCode(), 'links on the site are followed');
        self::assertSame(200, $this->get('https://demo.example/', ['Accept-Language' => 'fr, *'])->getStatusCode());
        self::assertSame(200, $this->get('https://demo.example/about-us', ['Accept-Language' => 'pt-PT'])->getStatusCode(), 'only the top address is negotiated');
        $own = $this->fixture->auth->request('GET', 'https://demo.example/')->withAttribute(\SightPress\Core\Template\ViewerPreferences::class, new \SightPress\Core\Template\ViewerPreferences('pt-PT'));
        self::assertSame('https://demo.example/pt-pt', $this->handler()->handle($own)->getHeaderLine('Location'), 'a signed-in person\'s own language wins');
        $home->setPublic(false, $this->fixture->pages->clock->now());
        $this->fixture->pages->repository()->flush();
        $members = $this->get('https://demo.example/', ['Accept-Language' => 'pt-PT']);
        self::assertSame([302, '/login'], [$members->getStatusCode(), $members->getHeaderLine('Location')], 'a members-only home page is never negotiated: sign in first');
        $home->setPublic(true, $this->fixture->pages->clock->now());
        $english = $this->fixture->auth->request('GET', 'https://demo.example/')->withAttribute(\SightPress\Core\Template\ViewerPreferences::class, new \SightPress\Core\Template\ViewerPreferences('en-GB'))->withHeader('Accept-Language', 'pt-PT');
        self::assertSame(200, $this->handler()->handle($english)->getStatusCode());
        unset($about);
    }

    public function testAltTextLeftEmptyComesFromTheMediaLibraryInThePagesLanguage(): void
    {
        $id = $this->fixture->blocks->addMedia();
        $file = $this->fixture->blocks->media()->find($id);
        $file?->describe('en-GB', 'A lighthouse at dusk', null, new \DateTimeImmutable());
        $file?->describe('pt-PT', 'Um farol ao anoitecer', null, new \DateTimeImmutable());
        $this->fixture->blocks->media()->save($file ?? throw new \LogicException());
        $this->fixture->pages->published('Welcome');
        $blocks = [['type' => 'image', 'version' => 1, 'data' => ['media' => $id]]];
        $about = $this->fixture->pages->published('Lighthouse', $blocks);
        $this->fixture->pages->translated($about, 'pt-PT', new PageContent('Farol', 'farol', null, null, $blocks));

        self::assertStringContainsString('alt="A lighthouse at dusk"', (string) $this->get('https://demo.example/lighthouse')->getBody());
        self::assertStringContainsString('alt="Um farol ao anoitecer"', (string) $this->get('https://demo.example/pt-pt/farol')->getBody());
        self::assertStringContainsString('![A lighthouse at dusk]', (string) $this->get('https://demo.example/lighthouse.md')->getBody());
    }

    public function testOldAddressesLeadToTheNewOnesAndDeletedOrTrashedPagesAreGone(): void
    {
        $pages = $this->fixture->pages;
        $home = $pages->published('Welcome');
        $pages->addresses()->setHome($home);
        $about = $pages->published('About');
        $team = $pages->published('Team', [], $about);
        $pages->translated($about, 'pt-PT', new PageContent('Sobre'));
        $pages->editor()->move($team, null, 9);
        $redirects = $pages->redirects();
        $redirects->add('en-GB', 'welcome-old', 'welcome');
        $redirects->add('pt-PT', 'antigo', 'nada');
        $redirects->gone('en-GB', 'deleted');

        foreach ([
            ['https://demo.example/about/team', 'https://demo.example/team'],
            ['https://demo.example/about/team.md', 'https://demo.example/team.md'],
            ['https://demo.example/welcome-old', 'https://demo.example/'],
            ['https://demo.example/welcome-old.md', 'https://demo.example/index.md'],
            ['https://demo.example/pt-pt/antigo', 'https://demo.example/pt-pt/nada'],
        ] as [$old, $new]) {
            $response = $this->get($old);
            self::assertSame([301, $new], [$response->getStatusCode(), $response->getHeaderLine('Location')], $old);
        }

        $about->changeState(\SightPress\Core\Rbac\Contract\PageState::Trash, $pages->clock->now());
        foreach (['https://demo.example/deleted', 'https://demo.example/about', 'https://demo.example/pt-pt/sobre'] as $gone) {
            try {
                $this->get($gone);
                self::fail($gone . ' is gone');
            } catch (\Slim\Exception\HttpGoneException) {
            }
        }
        $this->expectException(HttpNotFoundException::class);
        $this->get('https://demo.example/never-was');
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $uri, array $headers = [], ?Actor $actor = null): ResponseInterface
    {
        $request = $this->fixture->auth->request('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($actor !== null) {
            $request = $request->withAttribute(Actor::class, $actor);
        }

        return $this->handler()->handle($request);
    }

    private function handler(?\Psr\EventDispatcher\EventDispatcherInterface $events = null, string $config = 'production'): PublicPageHandler
    {
        $pages = $this->fixture->pages;

        return new PublicPageHandler(
            $pages->addresses(),
            new VoterAuthorizer([new PageVoter()]),
            $this->fixture->blocks->contexts($pages->site),
            $this->fixture->blocks->renderer(),
            $this->fixture->auth->templates,
            new \SightPress\Core\Tests\Support\Theme\TwigLayouts($this->fixture->auth->twig),
            $pages->site,
            $this->fixture->auth->http,
            $events ?? $pages,
            new \SightPress\Core\Page\PageMarkdown($this->fixture->blocks->contexts($pages->site), $this->fixture->blocks->renderer()),
            new \SightPress\Core\Http\AcceptNegotiator(),
            new \SightPress\Core\Tenant\TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => $config]),
            $this->fixture->blocks->texts($pages->site),
            $pages->redirects(),
            $pages->database->settings(),
        );
    }
}
