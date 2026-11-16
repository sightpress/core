<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Theme;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Kernel;
use SightPress\Core\Module\CoreModules;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;

/**
 * The default theme's files through the whole kernel: `/assets/…` on a site's host, with caching headers that
 * survive the security and session middleware.
 */
#[CoversNothing]
final class ThemeAssetsTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject();
        $this->project->directory->write('config/sites.php', '<?php return ' . var_export([
            'version' => 1,
            'sites' => ['demo' => SiteFixture::entry('demo', [
                'domains' => ['example.org'],
                'db_dsn' => SiteFixture::secretBox()->encrypt('sqlite:///:memory:'),
            ])],
        ], true) . ';');
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test', 'APP_SECRET' => SiteFixture::SECRET]),
            CoreModules::candidates(),
        );
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testTheStylesheetIsServedAndCachedForAYearWhenVersioned(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', 'http://example.org/assets/css/site.css?v=1')->withQueryParams(['v' => '1']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('.skip-link', (string) $response->getBody());
        self::assertNotSame('', $response->getHeaderLine('ETag'));
    }

    public function testTheTokensBecomeCustomPropertiesForEveryMode(): void
    {
        $css = (string) $this->kernel->handle(new ServerRequest('GET', 'http://example.org/assets/tokens.css'))->getBody();

        self::assertStringContainsString('--color-background: #ffffff;', $css);
        self::assertStringContainsString('--font-size-base: 1.125rem;', $css);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString('@media (prefers-contrast: more) and (prefers-color-scheme: dark)', $css);
    }

    public function testARevalidationAnswersNotModified(): void
    {
        $first = $this->kernel->handle(new ServerRequest('GET', 'http://example.org/assets/js/site.js'));
        $again = $this->kernel->handle(
            new ServerRequest('GET', 'http://example.org/assets/js/site.js')->withHeader('If-None-Match', $first->getHeaderLine('ETag')),
        );

        self::assertSame(304, $again->getStatusCode());
    }

    public function testTheThemeManifestAndTemplatesAreNotServed(): void
    {
        foreach (['/assets/../theme.json', '/assets/../templates/master.html.twig', '/assets/css/missing.css'] as $path) {
            self::assertSame(404, $this->kernel->handle(new ServerRequest('GET', 'http://example.org' . $path))->getStatusCode(), $path);
        }
    }
}
