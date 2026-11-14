<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\RateLimit;

use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Error\HttpTooManyRequestsException;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Http\RateLimit\RateLimitMiddleware;
use SightPress\Core\Tests\Support\CallbackHandler;
use SightPress\Core\Tests\Support\FixedClock;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(RateLimitMiddleware::class)]
#[CoversClass(RateLimiters::class)]
#[UsesClass(HttpTooManyRequestsException::class)]
final class RateLimitTest extends TestCase
{
    public function testStateChangingRequestsAreLimitedPerAddress(): void
    {
        $middleware = new RateLimitMiddleware(
            new RateLimiters(new InMemoryStorage(), ['write' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute']]),
            new FixedClock(new \DateTimeImmutable()),
        );
        $handler = new CallbackHandler(static fn () => new Response());
        $post = static fn (string $address) => new ServerRequest('POST', '/form', [], null, '1.1', ['REMOTE_ADDR' => $address]);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $middleware->process(new ServerRequest('GET', '/'), $handler)->getStatusCode());
        }
        $middleware->process($post('192.0.2.1'), $handler);
        $middleware->process($post('192.0.2.1'), $handler);
        self::assertSame(200, $middleware->process($post('192.0.2.2'), $handler)->getStatusCode());

        try {
            $middleware->process($post('192.0.2.1'), $handler);
            self::fail('The third request was accepted.');
        } catch (HttpTooManyRequestsException $exception) {
            self::assertGreaterThanOrEqual(1, $exception->retryAfter());
            self::assertLessThanOrEqual(61, $exception->retryAfter());
        }
    }

    public function testRequestsWithoutAnAddressShareOneLimit(): void
    {
        $middleware = new RateLimitMiddleware(
            new RateLimiters(new InMemoryStorage(), ['write' => ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute']]),
            new FixedClock(new \DateTimeImmutable('+1 hour')),
        );
        $handler = new CallbackHandler(static fn () => new Response());
        $middleware->process(new ServerRequest('DELETE', '/x'), $handler);

        try {
            $middleware->process(new ServerRequest('PATCH', '/x'), $handler);
            self::fail('The second request was accepted.');
        } catch (HttpTooManyRequestsException $exception) {
            self::assertSame(1, $exception->retryAfter(), 'never less than one second');
        }
    }

    public function testTheBuiltInPolicies(): void
    {
        $limiters = new RateLimiters(new InMemoryStorage());

        self::assertSame(5, $limiters->limiter('sign_in', 'ada@example.org')->consume(0)->getLimit());
        self::assertSame(60, $limiters->limiter('write', '192.0.2.1')->consume(0)->getLimit());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no rate limit policy "upload".');

        $limiters->limiter('upload', 'x');
    }
}
