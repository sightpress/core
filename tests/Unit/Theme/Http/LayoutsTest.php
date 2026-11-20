<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Auth\PasswordHasher;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Http\SectionAside;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageLayoutQuery;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Rbac\RoleRepository;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\Http\AppearanceHandler;
use SightPress\Core\User\UserRepository;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The standard layouts (T-3) through the kernel: pages in landing, sidebar and full-width layouts with the default
 * theme, the sidebar's "In this section", and a switch to a theme without two of them, which lists those pages first,
 * shows them in the standard layout and gives their layout back on the way back.
 */
#[CoversClass(SectionAside::class)]
#[CoversClass(PageLayoutQuery::class)]
#[CoversClass(AppearanceHandler::class)]
#[UsesNamespace('SightPress\Core')]
final class LayoutsTest extends TestCase
{
    private const string PASSWORD = 'Correct horse battery staple 7!';

    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $this->project->directory->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]],
        ]]));
        $this->kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
        $application = $this->kernel->console();
        $application->setAutoExit(false);
        self::assertSame(0, new ApplicationTester($application)->run([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'example.org',
            '--db' => 'sqlite:///' . $this->project->path() . '/sites/demo.sqlite',
            '--owner-type' => 'organization',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
        ], ['interactive' => false]));

        $site = $this->site();
        $now = new DateTimeImmutable();
        $section = $this->page($site, 'Our services', 'services', 'sidebar', null, $now);
        $this->page($site, 'Lending', 'lending', 'default', $section, $now);
        $draft = $this->page($site, 'Not yet', 'not-yet', 'default', $section, $now);
        $draft->changeState(PageState::Draft, $now);
        $this->page($site, 'Summer reading', 'summer', 'landing', null, $now);
        $this->page($site, 'Opening hours', 'hours', 'full-width', null, $now);
        $this->page($site, 'Lending rules', 'rules', 'sidebar', $section, $now);
        $this->page($site, 'On its own', 'alone', 'sidebar', null, $now);
        Services::get($site, PageRepository::class)->flush();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testEachLayoutWithTheDefaultTheme(): void
    {
        $browser = new Browser($this->kernel, 'http://example.org');

        $services = (string) $browser->request('GET', '/services')->getBody();
        self::assertStringContainsString('<div class="container layout-sidebar layout-sidebar--with-aside">', $services);
        self::assertStringContainsString('<aside class="layout-sidebar__aside" aria-labelledby="page-aside-title">', $services);
        self::assertStringContainsString('<h2 id="page-aside-title">In this section</h2>', $services);
        self::assertStringContainsString('<li><a href="/services/lending">Lending</a></li>', $services);
        self::assertStringNotContainsString('Not yet', $services, 'drafts are not listed');
        self::assertStringNotContainsString('Back to', $services, 'a top-level page has no page above it');

        $rules = (string) $browser->request('GET', '/services/rules')->getBody();
        self::assertStringContainsString('<li><a href="/services">Back to Our services</a></li>', $rules);

        self::assertStringContainsString('<div class="hero hero--landing">', (string) $browser->request('GET', '/summer')->getBody());
        self::assertStringContainsString('<div class="container layout-full-width">', (string) $browser->request('GET', '/hours')->getBody());
        $alone = (string) $browser->request('GET', '/alone')->getBody();
        self::assertStringContainsString('<div class="container layout-sidebar">', $alone, 'nothing in this section: no side column');
        self::assertStringNotContainsString('<aside', $alone);
        $lending = (string) $browser->request('GET', '/services/lending')->getBody();
        self::assertStringNotContainsString('page-aside-title', $lending, 'other layouts have no side column');
    }

    public function testSwitchingToAThemeWithoutALayoutListsItsPagesFirstAndSwitchingBackRestoresThem(): void
    {
        $admin = $this->signedIn();

        $confirm = $admin->submit('/admin/appearance', ['theme' => 'acme-harbour']);
        self::assertSame(200, $confirm->getStatusCode());
        $html = (string) $confirm->getBody();
        self::assertStringContainsString('<h1>Use Harbour?</h1>', $html);
        self::assertStringContainsString('<caption>Pages shown in the standard layout with Harbour</caption>', $html);
        self::assertStringContainsString('>Summer reading</a></td><td>landing</td>', $html);
        self::assertStringContainsString('>Opening hours</a></td><td>full-width</td>', $html);
        self::assertStringNotContainsString('Our services', $html, 'Harbour has a sidebar layout');
        self::assertSame('default', $this->theme());

        $admin->submit('/admin/appearance', ['theme' => 'acme-harbour', 'confirm' => '1']);
        self::assertSame('acme-harbour', $this->theme());
        $browser = new Browser($this->kernel, 'http://example.org');
        $summer = (string) $browser->request('GET', '/summer')->getBody();
        self::assertStringNotContainsString('hero--landing', $summer);
        self::assertStringContainsString('Summer reading', $summer);

        $admin->submit('/admin/appearance', ['theme' => 'default']);
        self::assertSame('default', $this->theme(), 'the default theme has every layout: no confirmation');
        self::assertStringContainsString('<div class="hero hero--landing">', (string) $browser->request('GET', '/summer')->getBody());
    }

    private function page(ContainerInterface $site, string $title, string $slug, string $layout, ?Page $parent, DateTimeImmutable $now): Page
    {
        $page = Services::get($site, PageEditor::class)->create('en-GB', new PageContent($title, $slug, 'About ' . strtolower($title) . '.', 'About ' . strtolower($title) . '.', [
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>' . $title . '.</p>']],
        ]), $parent);
        $page->setLayout($layout, $now);
        $page->changeState(PageState::Published, $now);

        return $page;
    }

    private function theme(): string
    {
        return Services::get($this->site(), \SightPress\Core\Theme\SiteTheme::class)->chosen();
    }

    private function site(): ContainerInterface
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find('demo');
        self::assertNotNull($site);
        $factory = Services::get($this->kernel->container(), SiteContainerFactory::class);
        $factory->reset();

        return $factory->forSite($site);
    }

    private function signedIn(): Browser
    {
        $container = $this->site();
        $user = Services::get($container, UserRepository::class)->findByEmail('ada@example.org');
        self::assertNotNull($user);
        $user->setPasswordHash(new PasswordHasher(['memory_cost' => 8, 'time_cost' => 1, 'threads' => 1])->hash(self::PASSWORD), new DateTimeImmutable());
        Services::get($container, UserRepository::class)->save($user);
        self::assertNotNull(Services::get($container, RoleRepository::class)->findRole('admin'));

        $browser = new Browser($this->kernel, 'http://example.org');
        self::assertSame(303, $browser->submit('/login', ['email' => 'ada@example.org', 'password' => self::PASSWORD])->getStatusCode());

        return $browser;
    }
}
