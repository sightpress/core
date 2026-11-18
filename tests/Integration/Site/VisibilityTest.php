<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Site;

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
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * Members-only visibility through the whole kernel (U-2, D24). On a `members` site an anonymous request for any page
 * (one stored as public too), its Markdown, the sitemap or `llms.txt` goes to sign-in and back, while the sign-in,
 * sign-up, code and password pages, the site's files and `robots.txt` stay open; a member reads every published page.
 * On a `public` site a member reads public and members pages and visitors only public ones (a members page asks them
 * to sign in). On any site a member gets 404 for drafts and archived pages, 410 for pages in the trash, 403 for the
 * admin and sees no admin link. The way back after signing in stays on the site.
 */
#[CoversNothing]
final class VisibilityTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private KernelSite $site;

    /** @var array<string, Page> */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('visibility', ['example.org'], 'production');
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        foreach (['home' => 'Welcome', 'about' => 'About us', 'members' => 'Members news', 'draft' => 'Draft', 'archived' => 'Old news', 'trashed' => 'Binned'] as $key => $title) {
            $this->pages[$key] = $editor->create('en-GB', new PageContent($title, $key === 'home' ? 'home' : null, 'About ' . $title . '.'));
        }
        $this->pages['members']->setPublic(false, new DateTimeImmutable());
        foreach (['home', 'about', 'members'] as $key) {
            $this->pages[$key]->changeState(PageState::Published, new DateTimeImmutable());
        }
        $this->pages['archived']->changeState(PageState::Archived, new DateTimeImmutable());
        $this->pages['trashed']->changeState(PageState::Trash, new DateTimeImmutable());
        Services::get($container, PageRepository::class)->flush();
        $this->user('max@example.org', 'member');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testOnAMembersOnlySiteVisitorsOnlyReachTheWayIn(): void
    {
        Services::get($this->site->container(), SiteSettings::class)->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);
        $guest = $this->guest();

        foreach (['/about-us' => '/login?return=%2Fabout-us', '/' => '/login', '/about-us.md' => '/login?return=%2Fabout-us.md', '/sitemap.xml' => '/login?return=%2Fsitemap.xml', '/llms.txt' => '/login?return=%2Fllms.txt', '/search?q=books' => '/login?return=%2Fsearch%3Fq%3Dbooks', '/members-news' => '/login?return=%2Fmembers-news'] as $path => $location) {
            $response = $guest->request('GET', $path);
            self::assertSame([302, $location], [$response->getStatusCode(), $response->getHeaderLine('Location')], $path);
            self::assertStringContainsString('noindex', $response->getHeaderLine('X-Robots-Tag'), $path);
        }
        foreach (['/login', '/login/forgot', '/login/invitation', '/login/setup', '/robots.txt'] as $path) {
            self::assertSame(200, $guest->request('GET', $path)->getStatusCode(), $path);
        }
        self::assertSame(404, $guest->request('GET', '/register')->getStatusCode(), 'reachable, but sign-up is off');
        self::assertSame(303, $guest->request('GET', '/register/code')->getStatusCode(), 'reachable: back to sign in');
        self::assertNotSame(302, $guest->request('GET', '/assets/css/site.css')->getStatusCode());
        self::assertStringContainsString("User-agent: *\nDisallow: /\n", (string) $guest->request('GET', '/robots.txt')->getBody());
        self::assertStringContainsString('This site is for its members. Sign in to read it.', (string) $guest->request('GET', '/login')->getBody());

        $signIn = $guest->submit('/login?return=%2Fabout-us', ['email' => 'max@example.org', 'password' => self::PASSWORD], '/login?return=%2Fabout-us');
        self::assertSame('/about-us', $signIn->getHeaderLine('Location'), 'back to the page asked for');
        foreach (['/', '/about-us', '/members-news'] as $path) {
            $page = $guest->request('GET', $path);
            self::assertSame(200, $page->getStatusCode(), $path);
            self::assertSame('noindex, nofollow', $page->getHeaderLine('X-Robots-Tag'), $path);
        }
        self::assertStringNotContainsString('<loc>', (string) $guest->request('GET', '/sitemap-en-gb.xml')->getBody() . (string) $guest->request('GET', '/sitemap.xml')->getBody());
        self::assertStringNotContainsString('/about-us', (string) $guest->request('GET', '/llms.txt')->getBody());
        $this->assertMemberLimits($guest);
    }

    public function testOnAPublicSiteVisitorsReadPublicPagesAndMembersTheRest(): void
    {
        $guest = $this->guest();
        self::assertSame(200, $guest->request('GET', '/about-us')->getStatusCode());
        $members = $guest->request('GET', '/members-news');
        self::assertSame([302, '/login?return=%2Fmembers-news'], [$members->getStatusCode(), $members->getHeaderLine('Location')]);
        self::assertSame(302, $guest->request('GET', '/members-news.md')->getStatusCode());
        $sitemap = (string) $guest->request('GET', '/sitemap.xml')->getBody();
        self::assertStringContainsString('/about-us</loc>', $sitemap);
        self::assertStringNotContainsString('members-news', $sitemap . (string) $guest->request('GET', '/llms.txt')->getBody());

        $guest->submit('/login', ['email' => 'max@example.org', 'password' => self::PASSWORD]);
        $read = $guest->request('GET', '/members-news');
        self::assertSame([200, 'noindex, nofollow'], [$read->getStatusCode(), $read->getHeaderLine('X-Robots-Tag')]);
        self::assertSame(200, $guest->request('GET', '/about-us')->getStatusCode());
        $this->assertMemberLimits($guest);
    }

    public function testTheWayBackAfterSigningInNeverLeavesTheSite(): void
    {
        Services::get($this->site->container(), SiteSettings::class)->set(SiteVisibility::SETTING, SiteVisibility::MEMBERS);

        foreach (['https://evil.example/', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', '/login', '/logout'] as $return) {
            $guest = $this->guest();
            $query = '/login?' . http_build_query(['return' => $return]);
            $response = $guest->submit($query, ['email' => 'max@example.org', 'password' => self::PASSWORD], $query);
            self::assertSame('/', $response->getHeaderLine('Location'), $return);
        }
    }

    private function assertMemberLimits(Browser $member): void
    {
        foreach (['/draft' => 404, '/old-news' => 404, '/binned' => 410, '/admin' => 403, '/admin/pages' => 403] as $path => $status) {
            self::assertSame($status, $member->request('GET', $path)->getStatusCode(), $path);
        }
        self::assertStringNotContainsString('href="/admin"', (string) $member->request('GET', '/about-us')->getBody(), 'no admin link');
    }

    private function guest(): Browser
    {
        return new Browser($this->site->kernel, 'https://example.org');
    }

    private function user(string $email, string $role): void
    {
        $container = $this->site->container();
        $user = new User($email, 'Max', 'Müller', 'Europe/London', new DateTimeImmutable());
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        $roles = Services::get($container, RoleRepository::class);
        $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
    }
}
