<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Error;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Error\NoSiteConfiguredException;
use SightPress\Core\Http\Error\NoSiteConfiguredMiddleware;
use SightPress\Core\Tenant\SiteRegistryProbe;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\TemporaryDirectory;
use Slim\Exception\HttpNotFoundException;
use Throwable;

#[CoversClass(NoSiteConfiguredMiddleware::class)]
#[UsesClass(SiteRegistryProbe::class)]
final class NoSiteConfiguredMiddlewareTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testResponsesPassThrough(): void
    {
        $response = $this->middleware(false)->process(
            new ServerRequest('GET', '/'),
            new CallbackHandler(static fn () => new Psr17Factory()->createResponse(200)),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testNotFoundBecomesNoSiteConfiguredWithoutSites(): void
    {
        $caught = $this->notFound(false, new HttpNotFoundException(new ServerRequest('GET', '/')));

        self::assertInstanceOf(NoSiteConfiguredException::class, $caught);
        self::assertInstanceOf(HttpNotFoundException::class, $caught->getPrevious());
    }

    public function testNotFoundStaysWhenSitesExist(): void
    {
        $original = new HttpNotFoundException(new ServerRequest('GET', '/'));

        self::assertSame($original, $this->notFound(true, $original));
    }

    public function testNoSiteConfiguredIsNotWrappedTwice(): void
    {
        $original = new NoSiteConfiguredException(new ServerRequest('GET', '/'));

        self::assertSame($original, $this->notFound(false, $original));
    }

    private function notFound(bool $hasSites, HttpNotFoundException $exception): Throwable
    {
        try {
            $this->middleware($hasSites)->process(
                new ServerRequest('GET', '/'),
                new CallbackHandler(static fn () => throw $exception),
            );
        } catch (Throwable $caught) {
            return $caught;
        }

        self::fail('The middleware did not rethrow.');
    }

    private function middleware(bool $hasSites): NoSiteConfiguredMiddleware
    {
        $file = $this->directory->write(
            'sites.php',
            '<?php return ["sites" => ' . ($hasSites ? '[["slug" => "demo"]]' : '[]') . '];',
        );

        return new NoSiteConfiguredMiddleware(new SiteRegistryProbe($file));
    }
}
