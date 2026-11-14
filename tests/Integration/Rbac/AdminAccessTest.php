<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Rbac;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Kernel;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Rbac\Contract\AdminAccessMiddleware;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\FakeSignIn;
use SightPress\Core\Tests\Support\Modules\HelloHandler;
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * An `/admin` route behind {@see AdminAccessMiddleware} in the whole kernel pipeline.
 */
#[CoversNothing]
final class AdminAccessTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject(sites: ['demo' => SiteFixture::entry('demo', ['domains' => ['example.org']])]);
        $module = new FakeModule('acme.admin', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('acme.admin', '/admin/acme', HelloHandler::class, middleware: [
                FakeSignIn::class,
                AdminAccessMiddleware::class,
            ]));
        });
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test']),
            [new ModuleCandidate($module, 'acme/admin')],
        );
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAnonymousVisitorsAreSentToSignIn(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', 'https://example.org/admin/acme'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?return=%2Fadmin%2Facme', $response->getHeaderLine('Location'));
    }

    public function testMembersGetA403WithLinksToTheSiteAndTheirAccount(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', 'https://example.org/admin/acme', ['X-Test-User' => 'member']));
        $html = (string) $response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<h1>You can&apos;t use the admin panel</h1>', $html);
        self::assertStringContainsString('<a href="/">Go to the home page</a>', $html);
        self::assertStringContainsString('<a href="/account">Go to My account</a>', $html);
        self::assertStringNotContainsString('href="/admin', $html);
    }

    public function testStaffGetThrough(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', 'https://example.org/admin/acme', ['X-Test-User' => 'editor']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello', (string) $response->getBody());
    }
}
