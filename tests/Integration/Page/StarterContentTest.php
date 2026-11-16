<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Page;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * C-6.4 acceptance: `site:create` writes and publishes the starter pages for a person or an organization, makes
 * Home the home page, fills the menus, and every page passes the publish checks without errors.
 */
#[CoversNothing]
final class StarterContentTest extends TestCase
{
    private TestProject $project;

    protected function setUp(): void
    {
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAnOrganizationGetsItsStarterPagesAndMenus(): void
    {
        $display = $this->create('library', 'organization', 'Riverside Library');
        self::assertStringContainsString('Published the starter pages: Welcome to Riverside Library, About us, Contact us, Privacy notice, Terms of use, Accessibility statement.', $display);
        self::assertStringContainsString('Added 5 starter pages to the main and footer menus.', $display);

        $browser = new Browser($this->kernel(), 'https://library.example');
        $home = (string) $browser->request('GET', '/')->getBody();
        self::assertStringContainsString('<h1>Welcome to Riverside Library</h1>', $home);
        self::assertStringContainsString('<div class="hero">', $home);
        self::assertMatchesRegularExpression('#<nav class="menu menu--main" aria-label="Main"[^>]*>\s*<ul id="main-menu">\s*<li><a href="/about">About us</a></li>\s*<li><a href="/contact">Contact us</a></li>#', $home);
        self::assertMatchesRegularExpression('#<nav class="menu menu--footer" aria-label="Footer">\s*<ul id="footer-menu">\s*<li><a href="/privacy">Privacy notice</a></li>\s*<li><a href="/terms">Terms of use</a></li>\s*<li><a href="/accessibility">Accessibility statement</a></li>#', $home);
        self::assertSame('https://library.example/', $browser->request('GET', '/home')->getHeaderLine('Location'));

        $contact = (string) $browser->request('GET', '/contact')->getBody();
        self::assertStringContainsString('Send us a message with the form below', $contact);
        self::assertStringContainsString('<button type="submit">Send message</button>', $contact);
        self::assertStringNotContainsString('ada@example.org', $contact, 'the recipient is never shown');
        self::assertStringContainsString('This statement applies to https://library.example.', (string) $browser->request('GET', '/accessibility')->getBody());

        $site = $this->services('library');
        $checker = Services::get($site, PublishChecker::class);
        $pages = Services::get($site, PageRepository::class)->children(null);
        self::assertCount(6, $pages);
        foreach ($pages as $page) {
            self::assertSame([], $checker->check($page)->errors(), (string) $page->translation('en-GB')?->title());
            self::assertSame(1, $page->authorId(), 'the first admin wrote them');
        }
    }

    public function testAPersonGetsPagesWrittenInTheFirstPerson(): void
    {
        $this->create('ada', 'person', 'Ada Example');

        $browser = new Browser($this->kernel(), 'https://ada.example');
        self::assertMatchesRegularExpression('/Welcome to my website\. I(&#0?39;|&apos;|’|\x27)m Ada Example\./', (string) $browser->request('GET', '/')->getBody());
        $about = (string) $browser->request('GET', '/about')->getBody();
        self::assertStringContainsString('<h1>About me</h1>', $about);
        self::assertStringContainsString('<a href="/contact">Contact me</a>', $about);
        self::assertStringContainsString('run by Ada Example', (string) $browser->request('GET', '/terms')->getBody());
    }

    private function create(string $slug, string $ownerType, string $name): string
    {
        $application = $this->kernel()->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run([
            'command' => 'site:create',
            'slug' => $slug,
            '--domain' => $slug . '.example',
            '--db' => TestDatabases::dsn($slug, $this->project->path() . '/sites', create: false),
            '--create-db' => true,
            '--owner-type' => $ownerType,
            '--locale' => 'en-GB',
            '--name' => $name,
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Example',
        ], ['interactive' => false, 'capture_stderr_separately' => true]);
        self::assertSame(0, $status, $tester->getErrorOutput());

        return $tester->getDisplay();
    }

    private function services(string $slug): ContainerInterface
    {
        $kernel = $this->kernel();
        $site = Services::get($kernel->container(), SiteRepository::class)->find($slug);
        self::assertNotNull($site);

        return Services::get($kernel->container(), SiteContainerFactory::class)->forSite($site);
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }
}
