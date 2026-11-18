<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Error;

use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Http\AcceptNegotiator;
use SightPress\Core\Http\Error\ErrorCatalogue;
use SightPress\Core\Http\Error\ErrorHandlingMiddleware;
use SightPress\Core\Http\Error\ErrorPage;
use SightPress\Core\Http\Error\ErrorResponder;
use SightPress\Core\Http\Error\HtmlErrorRenderer;
use SightPress\Core\Http\Error\HttpServiceUnavailableException;
use SightPress\Core\Http\Error\JsonErrorRenderer;
use SightPress\Core\Tests\Support\CallbackHandler;
use Slim\Exception\HttpNotFoundException;

#[CoversClass(ErrorResponder::class)]
#[CoversClass(ErrorHandlingMiddleware::class)]
#[UsesClass(ErrorCatalogue::class)]
#[UsesClass(ErrorPage::class)]
#[UsesClass(HtmlErrorRenderer::class)]
#[UsesClass(JsonErrorRenderer::class)]
#[UsesClass(AcceptNegotiator::class)]
#[UsesClass(HttpServiceUnavailableException::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesNamespace('SightPress\Core\Infrastructure\Crypto')]
final class ErrorResponderTest extends TestCase
{
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
    }

    public function testNotFoundIsAnHtmlPageAndIsNotLogged(): void
    {
        $request = new ServerRequest('GET', '/missing');

        $response = $this->responder()->respond($request, new HttpNotFoundException($request));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame(new HtmlErrorRenderer()->contentSecurityPolicy(), $response->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($response->hasHeader('X-Robots-Tag'));
        self::assertStringContainsString('<h1>Page not found</h1>', (string) $response->getBody());
        self::assertFalse($this->log->hasRecords('ERROR'));
    }

    public function testServerErrorsAreLoggedWithoutShowingDetails(): void
    {
        $request = new ServerRequest('POST', '/save');

        $response = $this->responder()->respond($request, new RuntimeException('database password wrong'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('database password', (string) $response->getBody());
        self::assertTrue($this->log->hasErrorThatContains('POST /save failed with 500: RuntimeException: database password wrong'));
    }

    public function testDebugModeShowsTheExceptionChain(): void
    {
        $request = new ServerRequest('GET', '/');
        $exception = new RuntimeException('outer', 0, new LogicException('inner'));

        $body = (string) $this->responder(debug: true)->respond($request, $exception)->getBody();

        self::assertStringContainsString('<summary>Technical details</summary>', $body);
        self::assertStringContainsString('RuntimeException: outer', $body);
        self::assertStringContainsString('Caused by LogicException: inner', $body);
    }

    public function testJsonClientsGetProblemDetailsAndPageHeaders(): void
    {
        $request = new ServerRequest('GET', '/', ['Accept' => 'application/json']);

        $response = $this->responder(noindex: true)->respond(
            $request,
            new HttpServiceUnavailableException($request, null, 120),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('120', $response->getHeaderLine('Retry-After'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringStartsWith('{"type":"about:blank","title":"Sorry, the service is unavailable","status":503', (string) $response->getBody());
    }

    public function testTheMiddlewarePassesResponsesThroughAndCatchesExceptions(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ErrorHandlingMiddleware($this->responder());
        $request = new ServerRequest('GET', '/');

        $ok = $middleware->process($request, new CallbackHandler(static fn () => $factory->createResponse(204)));
        $failed = $middleware->process($request, new CallbackHandler(static fn () => throw new RuntimeException('x')));

        self::assertSame(204, $ok->getStatusCode());
        self::assertSame(500, $failed->getStatusCode());
    }

    public function testErrorPagesAreInTheVisitorsLanguage(): void
    {
        $translations = \SightPress\Core\Template\Translations::fromDirectories([dirname(__DIR__, 4) . '/resources/translations']);
        $responder = $this->responder(translations: $translations);
        $site = \SightPress\Core\Tests\Support\PageFixture::site(['en-GB', 'es', 'pt-PT']);
        $context = new \SightPress\Core\Tenant\SiteContext($site, new \SightPress\Core\Tests\Support\ArrayContainer());
        $page = static fn (string $path, ?string $own = null): ServerRequest => (function () use ($path, $own, $context): ServerRequest {
            $request = new ServerRequest('GET', 'https://demo.example' . $path)->withAttribute(\SightPress\Core\Tenant\SiteContext::class, $context);

            return $own === null ? $request : $request->withAttribute(\SightPress\Core\Template\ViewerPreferences::class, new \SightPress\Core\Template\ViewerPreferences($own));
        })();

        $spanish = (string) $responder->respond($page('/es/nada'), new \Slim\Exception\HttpNotFoundException($page('/es/nada')))->getBody();
        self::assertStringContainsString('<html lang="es" dir="ltr">', $spanish);
        self::assertStringContainsString('<h1>No se ha encontrado la página</h1>', $spanish);
        self::assertStringContainsString('Ir a la página de inicio', $spanish);
        self::assertStringContainsString('<html lang="pt-PT"', (string) $responder->respond($page('/es/nada', 'pt-PT'), new RuntimeException('x'))->getBody(), 'a person’s own language wins');
        self::assertStringContainsString('<html lang="en-GB"', (string) $responder->respond($page('/about'), new RuntimeException('x'))->getBody(), 'the site’s default');
        self::assertStringContainsString('<html lang="en-GB"', (string) $responder->respond(new ServerRequest('GET', '/'), new RuntimeException('x'))->getBody(), 'no site: the admin default');
        $withSite = new \SightPress\Core\Tenant\SiteContext($site, new \SightPress\Core\Tests\Support\ArrayContainer([\SightPress\Core\Tenant\Site::class => \SightPress\Core\Tests\Support\PageFixture::site(['pt-PT', 'en-GB'])]));
        $displayed = new ServerRequest('GET', 'https://demo.example/x')->withAttribute(\SightPress\Core\Tenant\SiteContext::class, $withSite);
        self::assertStringContainsString('<html lang="pt-PT"', (string) $responder->respond($displayed, new RuntimeException('x'))->getBody(), 'the site as its admins set it up');
    }

    private function responder(bool $debug = false, bool $noindex = false, ?\SightPress\Core\Template\Translations $translations = null): ErrorResponder
    {
        $factory = new Psr17Factory();

        return new ErrorResponder(
            new ErrorCatalogue(),
            new HtmlErrorRenderer(),
            new JsonErrorRenderer(),
            new AcceptNegotiator(),
            $factory,
            $factory,
            new Logger('test', [$this->log], [new PsrLogMessageProcessor()]),
            $debug,
            $noindex,
            $translations,
        );
    }
}
