<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Menu;

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
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\KernelSite;
use SightPress\Core\User\Entity\User;
use SightPress\Core\User\UserRepository;

/**
 * An editor builds the main and footer menus in the admin, and visitors see them, with breadcrumbs, on every page
 * of the public site. Contributors can't open the menu editor.
 */
#[CoversNothing]
final class MenuFlowTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private KernelSite $site;

    /** @var array<string, Page> */
    private array $pages = [];

    protected function setUp(): void
    {
        $this->site = new KernelSite('menus');
        $container = $this->site->container();
        $editor = Services::get($container, PageEditor::class);
        $now = new DateTimeImmutable();
        $this->pages['home'] = $editor->create('en-GB', new PageContent('Welcome', 'home'));
        $this->pages['about'] = $editor->create('en-GB', new PageContent('About us', 'about'));
        $this->pages['team'] = $editor->create('en-GB', new PageContent('Our team', 'team'), $this->pages['about']);
        $this->pages['privacy'] = $editor->create('en-GB', new PageContent('Privacy', 'privacy'));
        $this->pages['plans'] = $editor->create('en-GB', new PageContent('Plans', 'plans'));
        foreach (['home', 'about', 'team', 'privacy'] as $key) {
            $this->pages[$key]->changeState(PageState::Published, $now);
        }
        Services::get($container, PageRepository::class)->flush();
        foreach (['bea@example.org' => ['Bea', 'editor'], 'cleo@example.org' => ['Cleo', 'contributor']] as $email => [$name, $role]) {
            $user = new User($email, $name, 'Tester', 'Europe/London', $now);
            $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), $now);
            Services::get($container, UserRepository::class)->save($user);
            $roles = Services::get($container, RoleRepository::class);
            $roles->assign((int) $user->id(), $roles->findRole($role) ?? throw new \LogicException($role));
        }
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testAnEditorBuildsTheMenusAndVisitorsSeeThem(): void
    {
        $bea = $this->signedIn('bea@example.org');
        self::assertStringContainsString('This menu is empty.', (string) $bea->request('GET', '/admin/menus')->getBody());

        foreach (['about', 'team', 'plans'] as $key) {
            $added = $bea->submit('/admin/menus', ['action' => 'add-page', 'menu' => 'main', 'page' => 'page-' . $this->pages[$key]->id()]);
            self::assertSame(303, $added->getStatusCode());
        }
        $bea->submit('/admin/menus', ['action' => 'add-page', 'menu' => 'footer', 'page' => 'page-' . $this->pages['privacy']->id()]);
        $bea->submit('/admin/menus', ['action' => 'add-link', 'menu' => 'footer', 'label' => 'Opening hours', 'url' => 'https://example.org/hours']);
        $refused = $bea->submit('/admin/menus', ['action' => 'add-link', 'menu' => 'footer', 'label' => 'Bad', 'url' => 'javascript:alert(1)']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('<title>1 error: Menus - Admin', (string) $refused->getBody());

        $page = (string) $bea->request('GET', '/admin/menus')->getBody();
        preg_match('/id="item-(\d+)-in"[^>]*aria-label="Move in, Our team"/', $page, $team);
        self::assertNotEmpty($team, 'the team entry has a "Move in, Our team" button');
        $moved = $bea->submit('/admin/menus', ['action' => 'in', 'item' => $team[1]]);
        self::assertSame(303, $moved->getStatusCode());
        $after = (string) $bea->request('GET', '/admin/menus')->getBody();
        self::assertStringContainsString('Our team has been moved in, under the entry before it.', $after);
        self::assertStringContainsString('aria-label="Move out, Our team" autofocus', $after, 'the first entry under another can\'t move in again, so "Move out" gets focus');
        self::assertStringContainsString('not shown until the page is published', $after);

        $visitor = new Browser($this->site->kernel, 'https://example.org');
        $team = (string) $visitor->request('GET', '/about/team')->getBody();
        self::assertMatchesRegularExpression('#<nav class="menu menu--main" aria-label="Main"[^>]*>\s*<ul id="main-menu">\s*<li><a href="/about">About us</a>\s*<ul id="main-menu-1">\s*<li><a href="/about/team" aria-current="page">Our team</a></li>#', $team);
        self::assertStringNotContainsString('Plans', $team, 'unpublished pages are left out of the menu');
        self::assertStringContainsString('<a href="/privacy">Privacy</a>', $team);
        self::assertStringContainsString('<a href="https://example.org/hours">Opening hours</a>', $team);
        self::assertMatchesRegularExpression('#<nav class="breadcrumbs" aria-label="Breadcrumb">.*<a href="/">Home</a>.*<a href="/about">About us</a>.*<a href="/about/team" aria-current="page">Our team</a>#s', $team);
        self::assertStringNotContainsString('class="breadcrumbs"', (string) $visitor->request('GET', '/')->getBody(), 'no breadcrumbs on the home page');
    }

    public function testContributorsCannotChangeMenus(): void
    {
        self::assertSame(403, $this->signedIn('cleo@example.org')->request('GET', '/admin/menus')->getStatusCode());
    }

    private function signedIn(string $email): Browser
    {
        $browser = new Browser($this->site->kernel, 'https://example.org');
        $browser->submit('/login', ['email' => $email, 'password' => self::PASSWORD]);

        return $browser;
    }
}
