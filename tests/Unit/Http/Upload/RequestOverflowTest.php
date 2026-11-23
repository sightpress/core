<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\RequestOverflowMiddleware;
use SightPress\Core\Http\Upload\RequestTooLargeException;
use SightPress\Core\Http\Upload\RequestTooLargeHandler;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Infrastructure\Clock\FrozenClock;
use SightPress\Core\Media\UploadRefusals;
use SightPress\Core\Tests\Support\ServerLimitsFixture;

#[CoversClass(RequestOverflowMiddleware::class)]
#[CoversClass(RequestTooLargeException::class)]
#[CoversClass(RequestTooLargeHandler::class)]
#[UsesNamespace('SightPress\Core\Http\Upload')]
#[UsesClass(\SightPress\Core\Infrastructure\Clock\FrozenClock::class)]
#[UsesClass(\SightPress\Core\Media\UploadRefusals::class)]
final class RequestOverflowTest extends TestCase
{
    public function testARequestLargerThanPhpReadsIsTooLargeNotAnExpiredForm(): void
    {
        $request = new ServerRequest('POST', 'https://demo.example/admin/media/upload', [
            'Content-Length' => (string) (65 * ServerLimitsFixture::MB + 1),
            'Referer' => 'https://demo.example/admin/media?view=list',
        ]);

        try {
            $this->middleware()->process($request, $this->handler());
            self::fail('Expected a 413.');
        } catch (RequestTooLargeException $exception) {
            self::assertSame(413, $exception->getCode());
            self::assertSame('413 Content Too Large', $exception->getTitle());
            self::assertSame(64 * ServerLimitsFixture::MB, $exception->limit);
            self::assertSame('/admin/media?view=list', $exception->back);
        }
    }

    public function testOtherRequestsGoOn(): void
    {
        foreach (['65', '', 'many'] as $length) {
            $request = new ServerRequest('POST', 'https://demo.example/admin/media/upload', $length === '' ? [] : ['Content-Length' => $length]);
            self::assertSame(204, $this->middleware()->process($request, $this->handler())->getStatusCode());
        }
    }

    public function testOnlyGoesBackToAPageOfTheSameSite(): void
    {
        $back = static fn (string $referer): ?string => RequestTooLargeException::backFrom(
            new ServerRequest('POST', 'https://demo.example/admin/media/upload', $referer === '' ? [] : ['Referer' => $referer]),
        );

        self::assertSame('/admin/pages/3/edit', $back('https://demo.example/admin/pages/3/edit'));
        self::assertNull($back(''));
        self::assertNull($back('https://evil.example/admin'));
        self::assertNull($back('https://demo.example'));
        self::assertNull($back('https://demo.example//evil.example/x'));
        self::assertNull($back('http:///x'));
    }

    public function testTheWebServersRefusalShowsTheSamePage(): void
    {
        $request = new ServerRequest('GET', 'https://demo.example' . RequestTooLargeHandler::PATH, ['Referer' => 'https://demo.example/account']);

        try {
            new RequestTooLargeHandler(ServerLimitsFixture::standard(proxy: 9 * ServerLimitsFixture::MB), $this->refusals())->handle($request);
            self::fail('Expected a 413.');
        } catch (RequestTooLargeException $exception) {
            self::assertSame(8 * ServerLimitsFixture::MB, $exception->limit);
            self::assertSame('/account', $exception->back);
        }
    }

    private function refusals(): UploadRefusals
    {
        return new UploadRefusals(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new FrozenClock(new DateTimeImmutable('2026-11-23T12:00:00Z')));
    }

    private function middleware(): RequestOverflowMiddleware
    {
        return new RequestOverflowMiddleware(ServerLimitsFixture::standard());
    }

    private function handler(): CallbackHandler
    {
        return new CallbackHandler(static fn (): Response => new Response(204));
    }
}
