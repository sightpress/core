<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SightPress\Core\Http\Session\SessionMiddleware;
use SightPress\Core\Kernel;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\FormHandler;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * The whole kernel pipeline: security headers, sessions only when needed, CSRF on forms, and the error pages.
 */
#[CoversNothing]
final class SecurityPipelineTest extends TestCase
{
    private TestProject $project;

    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->project = new TestProject(sites: [['slug' => 'demo']]);
        $module = new FakeModule('acme.form', register: static function (ModuleRegistrar $r): void {
            $r->routes()->add(new Route('form.show', '/form', FormHandler::class, ['GET', 'POST']));
        });
        $this->kernel = Kernel::fromConfig(
            new TenantConfigLoader()->load($this->project->path(), ['APP_ENV' => 'test']),
            [new ModuleCandidate($module, 'acme/form')],
        );
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testAFormRoundTrip(): void
    {
        $page = $this->kernel->handle(new ServerRequest('GET', 'https://example.org/form'));
        parse_str((string) $page->getBody(), $fields);
        $cookie = $this->sessionCookie($page);

        self::assertSame(200, $page->getStatusCode());
        self::assertSame('private, no-store', $page->getHeaderLine('Cache-Control'));
        self::assertSame('max-age=31536000', $page->getHeaderLine('Strict-Transport-Security'));
        self::assertStringContainsString("script-src 'self' 'nonce-", $page->getHeaderLine('Content-Security-Policy'));

        $saved = $this->kernel->handle(
            new ServerRequest('POST', 'https://example.org/form')
                ->withCookieParams([SessionMiddleware::SECURE_COOKIE => $cookie])
                ->withParsedBody($fields + ['title' => 'Hello']),
        );

        self::assertSame(200, $saved->getStatusCode());
        self::assertSame('saved', (string) $saved->getBody());
    }

    public function testAFormWithoutATokenGetsTheExpiredFormPage(): void
    {
        $response = $this->kernel->handle(
            new ServerRequest('POST', 'https://example.org/form')->withParsedBody(['title' => 'Hello']),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('<h1>This form has expired</h1>', (string) $response->getBody());
        self::assertStringStartsWith("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'a refused form starts no session');
    }

    public function testReadingAPageWithoutAFormSetsNoCookie(): void
    {
        $response = $this->kernel->handle(new ServerRequest('GET', 'https://example.org/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAWrongMethodIsReportedBeforeTheTokenCheck(): void
    {
        $response = $this->kernel->handle(new ServerRequest('DELETE', 'https://example.org/form'));

        self::assertSame(405, $response->getStatusCode());
    }

    private function sessionCookie(ResponseInterface $response): string
    {
        self::assertSame(1, preg_match('/^__Host-sightpress_session=([^;]+);/', $response->getHeaderLine('Set-Cookie'), $match));

        return $match[1];
    }
}
