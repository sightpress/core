<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Audit\AuditFilter;
use SightPress\Core\Audit\AuditLog;
use SightPress\Core\Audit\AuditRecorder;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Kernel;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\Browser;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Core\Tests\Support\Theme\HarbourTheme;
use SightPress\Core\Theme\Command\ApplyThemeCommand;
use SightPress\Core\Theme\Command\ListThemesCommand;
use SightPress\Core\Theme\SiteTheme;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Two sites on one tenant with a third-party theme installed: the theme is listed, applied to one site while the other
 * keeps the default theme, recorded in the audit log, and the site falls back when the theme is removed.
 */
#[CoversClass(ListThemesCommand::class)]
#[CoversClass(ApplyThemeCommand::class)]
#[CoversClass(SiteContainerFactory::class)]
#[CoversClass(AuditRecorder::class)]
#[UsesNamespace('SightPress\Core')]
final class SiteThemesTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    private string $stdout = '';

    private string $stderr = '';

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $this->install(true);
        foreach (['demo' => 'example.org', 'other' => 'other.example'] as $slug => $domain) {
            self::assertSame(0, $this->console([
                'command' => 'site:create',
                'slug' => $slug,
                '--domain' => $domain,
                '--db' => 'sqlite:///' . $this->project->path() . '/sites/' . $slug . '.sqlite',
                '--owner-type' => 'organization',
                '--admin-email' => 'ada@example.org',
                '--admin-first-name' => 'Ada',
                '--admin-last-name' => 'Lovelace',
            ]), $this->stderr);
        }
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAppliesAThemeToOneSiteWhileTheOtherKeepsTheDefault(): void
    {
        self::assertSame(0, $this->console(['command' => 'theme:list']));
        self::assertStringContainsString('default (SightPress Default) 0.', $this->stdout);
        self::assertStringContainsString(', available, the default for new sites; used by demo, other.', $this->stdout);
        self::assertStringContainsString('acme-harbour (Harbour) 1.2.0, available; no site uses it.', $this->stdout);

        self::assertSame(0, $this->console(['command' => 'site:theme:apply', 'slug' => 'demo', 'theme' => 'acme-harbour']), $this->stderr);
        self::assertSame("Applied the theme \"Harbour\" to demo.\n", $this->stdout);

        self::assertSame(0, $this->console(['command' => 'theme:list']));
        self::assertStringContainsString('acme-harbour (Harbour) 1.2.0, available; used by demo.', $this->stdout);
        $demo = (string) new Browser($this->kernel, 'http://example.org')->request('GET', '/')->getBody();
        $other = (string) new Browser($this->kernel, 'http://other.example')->request('GET', '/')->getBody();
        self::assertStringContainsString('<header>', $demo);
        self::assertStringNotContainsString('class="site-header"', $demo);
        self::assertStringContainsString('class="site-header"', $other);
        preg_match('#/assets/tokens\.css\?v=([0-9a-f]+)#', $demo, $demoVersion);
        preg_match('#/assets/tokens\.css\?v=([0-9a-f]+)#', $other, $otherVersion);
        self::assertNotSame($demoVersion[1] ?? '', $otherVersion[1] ?? '', 'The asset addresses change with the theme.');
        foreach ([$demo, $other] as $html) {
            self::assertStringContainsString('<link rel="canonical"', $html, 'Every theme prints core\'s head partial.');
        }

        $entries = Services::get($this->site('demo'), AuditLog::class)->find(new AuditFilter(), 10);
        self::assertSame('theme.applied', $entries[0]->action);
        self::assertNull($entries[0]->actorId);
        self::assertSame('Applied the theme Harbour.', $entries[0]->summary);
    }

    public function testListsAThemeTheTenantDoesNotAllow(): void
    {
        $this->project->directory->write('config/tenant.php', '<?php return ' . var_export([
            'log' => ['path' => $this->project->path() . '/var/log/test.log'],
            'themes' => ['allowed' => ['default']],
        ], true) . ';');
        $this->install(true);

        self::assertSame(0, $this->console(['command' => 'theme:list']));
        self::assertStringContainsString('acme-harbour (Harbour) 1.2.0, installed, not allowed (tenant setting "themes.allowed"); no site uses it.', $this->stdout);
    }

    public function testSaysWhatStopsATheme(): void
    {
        self::assertSame(1, $this->console(['command' => 'site:theme:apply', 'slug' => 'nope', 'theme' => 'acme-harbour']));
        self::assertSame("There is no site \"nope\". See the sites with site:list.\n", $this->stderr);
        self::assertSame(1, $this->console(['command' => 'site:theme:apply', 'slug' => 'demo', 'theme' => 'acme-gone']));
        self::assertSame("The theme \"acme-gone\" is not installed. Installed themes: default, acme-harbour.\n", $this->stderr);
    }

    public function testASiteWhoseThemeWasRemovedFallsBackAndIsListed(): void
    {
        self::assertSame(0, $this->console(['command' => 'site:theme:apply', 'slug' => 'demo', 'theme' => 'acme-harbour']));
        $this->install(false);

        self::assertSame(0, $this->console(['command' => 'theme:list']));
        self::assertSame("acme-harbour is not installed, but demo chose it; they are shown in the default theme.\n", $this->stderr);
        $html = (string) new Browser($this->kernel, 'http://example.org')->request('GET', '/')->getBody();
        self::assertStringContainsString('class="site-header"', $html);
        self::assertStringContainsString('The site falls back to the default theme', $this->project->log());
        self::assertSame('acme-harbour', Services::get($this->site('demo'), SiteTheme::class)->chosen());
    }

    private function install(bool $harbour): void
    {
        $packages = $harbour ? [['name' => 'acme/theme-harbour', 'type' => 'sightpress-theme', 'extra' => ['sightpress' => ['theme' => HarbourTheme::class]]]] : [];
        $this->project->directory->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
        $this->kernel = Kernel::boot($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]);
    }

    private function site(string $slug): \Psr\Container\ContainerInterface
    {
        $site = Services::get($this->kernel->container(), SiteRepository::class)->find($slug);
        self::assertNotNull($site);

        return Services::get($this->kernel->container(), SiteContainerFactory::class)->forSite($site);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        $application = $this->kernel->console();
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $status = $tester->run($input, ['interactive' => false, 'capture_stderr_separately' => true]);
        $this->stdout = $tester->getDisplay();
        $this->stderr = $tester->getErrorOutput();

        return $status;
    }
}
