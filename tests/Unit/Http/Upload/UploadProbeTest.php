<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SightPress\Core\Http\Upload\UploadProbe;
use SightPress\Core\Http\Upload\UploadProbeClient;
use SightPress\Core\Http\Upload\UploadProbeMiddleware;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(UploadProbe::class)]
#[CoversClass(UploadProbeClient::class)]
#[CoversClass(UploadProbeMiddleware::class)]
#[UsesNamespace('SightPress\Core\Infrastructure\Clock')]
final class UploadProbeTest extends TestCase
{
    private const string SECRET = 'a-secret-of-at-least-32-characters-long';

    private const int MB = 1024 * 1024;

    public function testASignatureIsGoodForOneSizeAndFiveMinutes(): void
    {
        $probe = self::probe();
        parse_str($probe->query(100), $query);

        self::assertTrue($probe->canSign());
        self::assertTrue($probe->accepts($query, '100'));
        self::assertFalse($probe->accepts($query, '101'), 'Another body size.');
        self::assertFalse($probe->accepts(['signature' => 'x'] + $query, '100'), 'A wrong signature.');
        self::assertFalse($probe->accepts(['bytes' => '101'] + $query, '101'), 'A changed size.');
        self::assertFalse($probe->accepts(['expires' => 'soon'] + $query, '100'));
        self::assertFalse($probe->accepts([], '100'));
        self::assertTrue(self::probe('2026-11-23T12:05:00Z')->accepts($query, '100'));
        self::assertFalse(self::probe('2026-11-23T12:05:01Z')->accepts($query, '100'), 'Expired.');
        self::assertFalse(self::probe('2026-11-23T11:59:59Z')->accepts($query, '100'), 'Valid for longer than a probe can be.');
        self::assertFalse(new UploadProbe(null, self::clock())->accepts($query, '100'), 'No secret.');
        self::assertFalse(new UploadProbe(null, self::clock())->canSign());
    }

    public function testTheMiddlewareAnswersOnlyASignedPost(): void
    {
        $probe = self::probe();
        $middleware = new UploadProbeMiddleware($probe, new Psr17Factory());
        $next = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404);
            }
        };
        parse_str($probe->query(10), $query);
        $request = new ServerRequest('POST', UploadProbe::PATH, ['Content-Length' => '10']);

        $answer = $middleware->process($request->withQueryParams($query), $next);
        self::assertSame(204, $answer->getStatusCode());
        self::assertSame('accepted', $answer->getHeaderLine(UploadProbe::ANSWER_HEADER));
        self::assertSame(404, $middleware->process($request, $next)->getStatusCode());
        self::assertSame(404, $middleware->process(new ServerRequest('GET', UploadProbe::PATH)->withQueryParams($query), $next)->getStatusCode());
        self::assertSame(404, $middleware->process(new ServerRequest('POST', '/other', ['Content-Length' => '10'])->withQueryParams($query), $next)->getStatusCode());
    }

    public function testFindsTheWebServersLimitToTheMegabyte(): void
    {
        $sizes = [];
        $result = self::client(static function (int $bytes) use (&$sizes): MockResponse {
            $sizes[] = $bytes;

            return $bytes <= 65 * self::MB ? self::accepted() : new MockResponse('', ['http_code' => 413]);
        })->measure('http://nginx/', 200 * self::MB);

        self::assertSame(['accepted' => 65 * self::MB, 'refused' => 66 * self::MB], $result);
        self::assertSame([1, 2, 4, 8, 16, 32, 64, 128, 96, 80, 72, 68, 66, 65], array_map(static fn (int $size): int => $size / self::MB, $sizes));
    }

    public function testStopsAtTheLargestSizeWorthTrying(): void
    {
        $result = self::client(static fn (): MockResponse => self::accepted())->measure('https://example.org', 3 * self::MB);

        self::assertSame(['accepted' => 3 * self::MB, 'refused' => null], $result);
    }

    public function testEvenTheSmallestSizeCanBeRefused(): void
    {
        $result = self::client(static fn (): MockResponse => new MockResponse('', ['http_code' => 413]))->measure('http://nginx', 8 * self::MB);

        self::assertSame(['accepted' => 0, 'refused' => self::MB], $result);
    }

    public function testSendsTheWholeBody(): void
    {
        $sent = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringStartsWith('http://nginx' . UploadProbe::PATH . '?bytes=1048576&expires=', $url);
            $body = $options['body'];
            self::assertIsCallable($body);
            while (($chunk = $body(65536)) !== '') {
                self::assertIsString($chunk);
                $sent += strlen($chunk);
            }

            return self::accepted();
        });

        new UploadProbeClient($http, self::probe())->measure('http://nginx', self::MB);

        self::assertSame(self::MB, $sent);
    }

    public function testExplainsWhyItCantTell(): void
    {
        foreach ([
            ['ftp://nginx', self::client(static fn (): MockResponse => self::accepted()), 'must start with http://'],
            ['not a url', self::client(static fn (): MockResponse => self::accepted()), 'must start with http://'],
            ['http://nginx', new UploadProbeClient(new MockHttpClient(), new UploadProbe(null, self::clock())), 'needs APP_SECRET'],
            ['http://nginx', self::client(static fn (): MockResponse => new MockResponse('', ['http_code' => 404])), 'got the answer 404'],
            ['http://nginx', self::client(static fn (): MockResponse => new MockResponse('', ['http_code' => 204])), 'got the answer 204'],
            ['http://nginx', new UploadProbeClient(new MockHttpClient(static fn (): never => throw new TransportException('refused')), self::probe()), 'couldn’t reach'],
        ] as [$url, $client, $message]) {
            try {
                $client->measure($url, self::MB);
                self::fail('Expected: ' . $message);
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /**
     * @param callable(int): MockResponse $answer gets the size of the body
     */
    private static function client(callable $answer): UploadProbeClient
    {
        return new UploadProbeClient(new MockHttpClient(static function (string $method, string $url) use ($answer): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $answer((int) $query['bytes']);
        }), self::probe());
    }

    private static function accepted(): MockResponse
    {
        return new MockResponse('', ['http_code' => 204, 'response_headers' => [UploadProbe::ANSWER_HEADER . ': accepted']]);
    }

    private static function probe(string $now = '2026-11-23T12:00:00Z'): UploadProbe
    {
        return new UploadProbe(self::SECRET, self::clock($now));
    }

    private static function clock(string $now = '2026-11-23T12:00:00Z'): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable($now));
    }
}
