<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Site;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Kernel;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestDatabases;
use SightPress\Core\Tests\Support\TestProject;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * C-8.1: `site:create --with-demo-content` makes a site with the demo media, a published page with every kind of
 * block (listed in the sitemap and in the main menu) and an account for each role, whose printed setup code works.
 */
#[CoversNothing]
final class DemoSiteCreationTest extends TestCase
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

    public function testADemoSiteHasEveryBlockMediaAndAnAccountForEachRole(): void
    {
        $application = $this->kernel()->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run([
            'command' => 'site:create',
            'slug' => 'demo',
            '--domain' => 'demo.example',
            '--db' => TestDatabases::dsn('demo', $this->project->path() . '/sites', create: false),
            '--create-db' => true,
            '--owner-type' => 'organization',
            '--locale' => 'en-GB',
            '--admin-email' => 'ada@example.org',
            '--admin-first-name' => 'Ada',
            '--admin-last-name' => 'Lovelace',
            '--with-demo-content' => true,
        ], ['interactive' => false, 'capture_stderr_separately' => true]);

        self::assertSame(0, $status, $tester->getErrorOutput());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Added 5 demo files to the media library, described and published.', $display);
        self::assertStringContainsString('Added the demo page "Every kind of block" (/every-block) and a page under it.', $display);
        self::assertSame(1, preg_match('#Demo editor Bea: Give the setup code (\d{3} \d{3}) to bea@example\.org\.#', $display, $code), $display);
        self::assertStringContainsString('Demo contributor Cleo: Give the setup code', $display);
        self::assertStringContainsString('Demo member Max: Give the setup code', $display);

        $kernel = $this->kernel();
        $visitor = new Browser($kernel, 'https://demo.example');
        $page = $visitor->request('GET', '/every-block');
        $html = (string) $page->getBody();
        self::assertSame(200, $page->getStatusCode(), $html);
        foreach (['<h1>Every kind of block</h1>', '<img', '<figure', '<video', '<track', '<audio', 'Opening hours', '<form', 'href="/every-block/under"'] as $part) {
            self::assertStringContainsString($part, $html);
        }
        self::assertSame(200, $visitor->request('GET', '/every-block/under')->getStatusCode());
        $sitemap = (string) $visitor->request('GET', '/sitemap.xml')->getBody();
        self::assertStringContainsString('<loc>https://demo.example/every-block</loc>', $sitemap);
        self::assertStringContainsString('<loc>https://demo.example/every-block/under</loc>', $sitemap);

        // The editor types the printed code, chooses a password and reaches the admin.
        $bea = new Browser($kernel, 'https://demo.example');
        self::assertSame('/login/password', $bea->submit('/login/setup', ['email' => 'bea@example.org', 'code' => $code[1]])->getHeaderLine('Location'));
        self::assertSame('/login', $bea->submit('/login/password', ['password' => 'bea editor long password'])->getHeaderLine('Location'));
        self::assertSame('/admin', $bea->submit('/login', ['email' => 'bea@example.org', 'password' => 'bea editor long password'])->getHeaderLine('Location'));
    }

    private function kernel(): Kernel
    {
        return Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }
}
