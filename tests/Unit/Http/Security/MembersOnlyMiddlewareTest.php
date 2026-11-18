<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Security;

use DI\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Security\MembersOnlyMiddleware;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\SiteFixture;

#[CoversClass(MembersOnlyMiddleware::class)]
#[CoversClass(SiteVisibility::class)]
#[UsesNamespace('SightPress\Core')]
final class MembersOnlyMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function closedAddresses(): iterable
    {
        yield 'a page stored as public' => ['GET', '/about', '/login?return=%2Fabout'];
        yield 'with its query' => ['GET', '/about?tab=2', '/login?return=%2Fabout%3Ftab%3D2'];
        yield 'the home page' => ['GET', '/', '/login'];
        yield 'its Markdown' => ['GET', '/about.md', '/login?return=%2Fabout.md'];
        yield 'the sitemap' => ['GET', '/sitemap.xml', '/login?return=%2Fsitemap.xml'];
        yield 'llms.txt' => ['GET', '/llms.txt', '/login?return=%2Fllms.txt'];
        yield 'a language sitemap' => ['HEAD', '/sitemap-pt-pt.xml', '/login?return=%2Fsitemap-pt-pt.xml'];
        yield 'search' => ['GET', '/search?q=x', '/login?return=%2Fsearch%3Fq%3Dx'];
        yield 'media' => ['GET', '/media/abc.jpg', '/login?return=%2Fmedia%2Fabc.jpg'];
        yield 'a form sent' => ['POST', '/contact-form', '/login'];
        yield 'a look-alike of an open address' => ['GET', '/loginx', '/login?return=%2Floginx'];
        yield 'a path that looks like another host' => ['GET', '/%2F%2Fevil.example/x', '/login?return=%2F%252F%252Fevil.example%2Fx'];
    }

    #[DataProvider('closedAddresses')]
    public function testAnonymousVisitorsOfAMembersOnlySiteAreSentToSignInAndBack(string $method, string $path, string $location): void
    {
        $response = $this->process(new ServerRequest($method, 'https://demo.example' . $path), SiteVisibility::MEMBERS);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($location, $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openAddresses(): iterable
    {
        foreach (['/login', '/login/forgot', '/login/forgot/code', '/login/invitation', '/login/invitation/account', '/login/setup', '/login/password', '/logout', '/register', '/register/code', '/robots.txt', '/assets/css/site.css', '/branding/logo.svg', '/favicon.ico', '/icon.svg', '/site.webmanifest'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('openAddresses')]
    public function testSigningInSigningUpCodesFilesAndRobotsStayOpen(string $path): void
    {
        self::assertSame(204, $this->process(new ServerRequest('GET', 'https://demo.example' . $path), SiteVisibility::MEMBERS)->getStatusCode());
    }

    public function testSignedInPeoplePublicSitesAndRequestsWithoutASitePassThrough(): void
    {
        $page = new ServerRequest('GET', 'https://demo.example/about');

        self::assertSame(204, $this->process($page->withAttribute(Actor::class, new Actor(7)), SiteVisibility::MEMBERS)->getStatusCode());
        self::assertSame(204, $this->process($page, SiteVisibility::PUBLIC)->getStatusCode());
        self::assertSame(204, $this->process($page, null)->getStatusCode(), 'public by default');
        self::assertSame(204, $this->middleware()->process($page, $this->handler())->getStatusCode(), 'no site');
        $noSettings = $page->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), new Container()));
        self::assertSame(204, $this->middleware()->process($noSettings, $this->handler())->getStatusCode());
    }

    public function testAPageIsOpenToEveryoneOnlyWhenItAndTheSiteArePublic(): void
    {
        $public = new ArraySiteSettings();
        $members = new ArraySiteSettings([SiteVisibility::SETTING => SiteVisibility::MEMBERS]);

        self::assertTrue(SiteVisibility::isOpenToEveryone(true, $public));
        self::assertFalse(SiteVisibility::isOpenToEveryone(false, $public));
        self::assertFalse(SiteVisibility::isOpenToEveryone(true, $members));
        self::assertTrue(SiteVisibility::isMembersOnly($members));
        self::assertFalse(SiteVisibility::isMembersOnly($public));
    }

    private function process(ServerRequestInterface $request, ?string $visibility): ResponseInterface
    {
        $settings = new ArraySiteSettings($visibility === null ? [] : [SiteVisibility::SETTING => $visibility]);
        $request = $request->withAttribute(SiteContext::class, new SiteContext(SiteFixture::site(), new Container([SiteSettings::class => $settings])));

        return $this->middleware()->process($request, $this->handler());
    }

    private function middleware(): MembersOnlyMiddleware
    {
        return new MembersOnlyMiddleware(new Psr17Factory());
    }

    private function handler(): CallbackHandler
    {
        return new CallbackHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Response(204));
    }
}
