<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Error;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Http\Error\ErrorCatalogue;
use SightPress\Core\Http\Error\ErrorPage;
use SightPress\Core\Http\Error\HttpServiceUnavailableException;
use SightPress\Core\Http\Error\HttpTooManyRequestsException;
use SightPress\Core\Http\Error\NoSiteConfiguredException;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpGoneException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use Throwable;

#[CoversClass(ErrorCatalogue::class)]
#[CoversClass(ErrorPage::class)]
#[CoversClass(HttpServiceUnavailableException::class)]
#[CoversClass(HttpTooManyRequestsException::class)]
#[CoversClass(\SightPress\Core\Http\Csrf\CsrfTokenMismatchException::class)]
final class ErrorCatalogueTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, int, string, string}>
     */
    public static function exceptions(): iterable
    {
        $request = new ServerRequest('GET', '/x');

        yield 'any exception' => [new RuntimeException('secret'), 500, 'Sorry, there is a problem with the service', 'Try again later.'];
        yield '400' => [new HttpBadRequestException($request), 400, 'Sorry, something is wrong with this request', 'Go back'];
        yield '401' => [new HttpUnauthorizedException($request), 401, 'Sign in to see this page', 'sign in'];
        yield 'expired form' => [
            new \SightPress\Core\Http\Csrf\CsrfTokenMismatchException($request),
            403,
            'This form has expired',
            'reload the page and send the form again',
        ];
        yield '403' => [new HttpForbiddenException($request), 403, 'You do not have permission to see this page', 'ask the person'];
        yield '404' => [new HttpNotFoundException($request), 404, 'Page not found', 'check it is correct'];
        yield '405' => [new HttpMethodNotAllowedException($request), 405, 'This page cannot do that', 'Go back'];
        yield '410' => [new HttpGoneException($request), 410, 'This page has been removed', 'no longer available'];
        yield '429' => [new HttpTooManyRequestsException($request), 429, 'Too many requests', 'Try again later.'];
        yield '501' => [new HttpNotImplementedException($request), 501, 'Sorry, there is a problem with the service', 'Try again later.'];
        yield '503' => [new HttpServiceUnavailableException($request), 503, 'Sorry, the service is unavailable', 'Try again later.'];
        yield 'status out of range' => [new HttpException($request, 'odd', 302), 500, 'Sorry, there is a problem with the service', 'Try again later.'];
    }

    #[DataProvider('exceptions')]
    public function testEachErrorGetsAPlainPage(Throwable $exception, int $status, string $title, string $text): void
    {
        $page = new ErrorCatalogue()->pageFor($exception);

        self::assertSame($status, $page->status);
        self::assertSame($title, $page->title);
        self::assertStringContainsString($text, implode(' ', $page->paragraphs));
        self::assertStringNotContainsString('secret', implode(' ', $page->paragraphs));
        self::assertTrue($page->homeLink);
        self::assertSame([], $page->headers);
    }

    public function testNoSiteConfiguredHasNoHomeLink(): void
    {
        $page = new ErrorCatalogue()->pageFor(new NoSiteConfiguredException(new ServerRequest('GET', '/')));

        self::assertSame(404, $page->status);
        self::assertSame('No site configured', $page->title);
        self::assertFalse($page->homeLink);
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        $exception = new HttpMethodNotAllowedException(new ServerRequest('PUT', '/'))->setAllowedMethods(['GET', 'POST']);

        self::assertSame(['Allow' => 'GET, POST'], new ErrorCatalogue()->pageFor($exception)->headers);
    }

    /**
     * @return iterable<string, array{positive-int, string}>
     */
    public static function waits(): iterable
    {
        yield 'seconds' => [30, 'Try again in a minute.'];
        yield 'one minute' => [60, 'Try again in a minute.'];
        yield 'minutes' => [61, 'Try again in 2 minutes.'];
    }

    /**
     * @param positive-int $seconds
     */
    #[DataProvider('waits')]
    public function testRetryAfterIsSentAndSaidInWords(int $seconds, string $text): void
    {
        $request = new ServerRequest('GET', '/');

        foreach ([
            new HttpTooManyRequestsException($request, null, $seconds),
            new HttpServiceUnavailableException($request, null, $seconds),
        ] as $exception) {
            self::assertSame($seconds, $exception->retryAfter());
            $page = new ErrorCatalogue()->pageFor($exception);

            self::assertSame(['Retry-After' => (string) $seconds], $page->headers);
            self::assertContains($text, $page->paragraphs);
        }
    }

    public function testServiceUnavailableHasADefaultMessage(): void
    {
        $exception = new HttpServiceUnavailableException(new ServerRequest('GET', '/'));

        self::assertSame('Service unavailable.', $exception->getMessage());
        self::assertSame(503, $exception->getCode());
        self::assertNull($exception->retryAfter());
    }
}
