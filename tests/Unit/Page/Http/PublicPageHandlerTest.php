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

    protected function setUp(): void
    {
        $this->fixture = new ContactFixture();
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
        $this->fixture->pages->editor()->save($about, 'pt-PT', new PageContent('Sobre nós', 'sobre'));
        $team = $this->fixture->pages->published('Team', [], $about);

        $html = (string) $this->get('https://demo.example/about-us')->getBody();
        $event = end($this->fixture->pages->events);
        self::assertInstanceOf(\SightPress\Core\Page\Contract\PublicPageRendering::class, $event);
        self::assertSame([(int) $about->id(), 'en-GB', '/about-us', 'https://demo.example', 'About us', false, true], [$event->pageId, $event->locale, $event->url, $event->baseUrl, $event->title, $event->home, $event->public]);
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

                return $event;
            }
        };

        $html = (string) $this->handler($events)->handle($this->fixture->auth->request('GET', 'https://demo.example/'))->getBody();

        self::assertStringContainsString("<meta name=\"test\" content=\"1\">\n<meta name=\"test\" content=\"2\">", $html);
        self::assertStringContainsString('<li><a href="/visit">Visit us</a></li>', $html);
    }

    public function testAddressesThatAreNotThePagesOwnMovePermanently(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->pages->published('About');

        foreach ([
            'https://demo.example/about/?x=1' => 'https://demo.example/about?x=1',
            'https://demo.example/welcome' => 'https://demo.example/',
            'https://demo.example/about//' => 'https://demo.example/about',
        ] as $from => $to) {
            $response = $this->get($from);
            self::assertSame(301, $response->getStatusCode(), $from);
            self::assertSame($to, $response->getHeaderLine('Location'), $from);
        }
    }

    public function testDraftsMissingPagesAndMembersOnlyPagesForVisitorsAreNotFound(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->pages->editor()->create('en-GB', new PageContent('Draft'));
        $members = $this->fixture->pages->published('Members');
        $members->setPublic(false, $this->fixture->pages->clock->now());
        $this->fixture->pages->repository()->flush();

        foreach (['/draft', '/missing', '/members', '/pt-pt'] as $path) {
            try {
                $this->get('https://demo.example' . $path);
                self::fail('Expected a 404 for ' . $path);
            } catch (HttpNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $signedIn = $this->get('https://demo.example/members', [], new Actor(7));
        self::assertSame(200, $signedIn->getStatusCode());
        self::assertSame('private, no-cache', $signedIn->getHeaderLine('Cache-Control'));
    }

    public function testAPageWithAFormIsPrivate(): void
    {
        $this->fixture->pages->published('Welcome');
        $this->fixture->page();

        $response = $this->get('https://demo.example/contact');

        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Send message', (string) $response->getBody());
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

    private function handler(?\Psr\EventDispatcher\EventDispatcherInterface $events = null): PublicPageHandler
    {
        $pages = $this->fixture->pages;

        return new PublicPageHandler(
            $pages->addresses(),
            new VoterAuthorizer([new PageVoter()]),
            $this->fixture->blocks->contexts($pages->site),
            $this->fixture->blocks->renderer(),
            $this->fixture->auth->templates,
            $this->fixture->auth->twig,
            $pages->site,
            $this->fixture->auth->http,
            $events ?? $pages,
        );
    }
}
