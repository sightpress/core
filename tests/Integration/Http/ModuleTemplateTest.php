<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Kernel;
use SightPress\Core\Module\ModuleCandidate;
use SightPress\Core\Tenant\TenantConfigLoader;
use SightPress\Core\Tests\Support\FakeModule;
use SightPress\Core\Tests\Support\Modules\TemplateHandler;
use SightPress\Core\Tests\Support\TestProject;
use SightPress\Module\ModuleRegistrar;
use SightPress\Module\Routing\Route;

/**
 * A module's template rendered through the kernel: escaped, translated, dated, and allowed by the page's CSP nonce.
 */
#[CoversNothing]
final class ModuleTemplateTest extends TestCase
{
    public function testAModuleRendersItsTemplateWithTheResponsesNonce(): void
    {
        $project = new TestProject(sites: [['slug' => 'demo']]);
        try {
            $module = new FakeModule('acme', register: static function (ModuleRegistrar $r): void {
                $r->views()->add('acme', dirname(__DIR__, 2) . '/Support/Views');
                $r->routes()->add(new Route('acme.hello', '/hello', TemplateHandler::class));
            });
            $kernel = Kernel::fromConfig(
                new TenantConfigLoader()->load($project->path(), ['APP_ENV' => 'production']),
                [new ModuleCandidate($module, 'acme/hello')],
            );

            $response = $kernel->handle(new ServerRequest('GET', 'https://example.org/hello'));
            $html = (string) $response->getBody();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, preg_match("/'nonce-([^']+)'/", $response->getHeaderLine('Content-Security-Policy'), $nonce));
            self::assertStringContainsString('<style nonce="' . $nonce[1] . '">', $html);
            self::assertStringContainsString('<h1>Hello, &lt;Ada&gt;</h1>', $html);
            self::assertStringContainsString('<p>14 November 2026</p>', $html);
            self::assertDirectoryExists($project->path() . '/var/cache/production/twig');
        } finally {
            $project->remove();
        }
    }
}
