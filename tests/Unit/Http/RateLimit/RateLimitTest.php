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

    public function testThePartsOfALargeFileHaveTheirOwnLimit(): void
    {
        $middleware = new RateLimitMiddleware(
            new RateLimiters(new InMemoryStorage(), [
                'write' => ['policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
                'upload_part' => ['policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
            ]),
            new FixedClock(new \DateTimeImmutable()),
        );
        $handler = new CallbackHandler(static fn () => new Response());
        $part = new ServerRequest('PATCH', '/admin/media/uploads/' . str_repeat('a', 32), [], null, '1.1', ['REMOTE_ADDR' => '192.0.2.1']);

        $middleware->process($part, $handler);
        $middleware->process($part, $handler);
        self::assertSame(200, $middleware->process($part->withMethod('POST'), $handler)->getStatusCode(), 'parts leave the write limit alone');

        foreach ([$part, $part->withMethod('DELETE'), $part->withUri($part->getUri()->withPath('/admin/pages/1'))] as $refused) {
            try {
                $middleware->process($refused, $handler);
                self::fail('The request was accepted.');
            } catch (HttpTooManyRequestsException $exception) {
                self::assertGreaterThanOrEqual(1, $exception->retryAfter());
            }
        }
    }

    public function testTheBuiltInPolicies(): void
    {
        $limiters = new RateLimiters(new InMemoryStorage());

        self::assertSame(5, $limiters->limiter('sign_in', 'ada@example.org')->consume(0)->getLimit());
        self::assertSame(60, $limiters->limiter('write', '192.0.2.1')->consume(0)->getLimit());
        self::assertSame(600, $limiters->limiter('upload_part', '192.0.2.1')->consume(0)->getLimit());
        self::assertSame(5, $limiters->limiter('verification_target', 'ada@example.org')->consume(0)->getLimit());
        self::assertSame(20, $limiters->limiter('verification_client', '192.0.2.1')->consume(0)->getLimit());
        self::assertSame(10, $limiters->limiter('verification_check', '192.0.2.1')->consume(0)->getLimit());
        $sms = new RateLimiters(new InMemoryStorage(), RateLimiters::smsPolicies(['per_number' => 3, 'per_client' => 10, 'per_account' => 5, 'per_site' => 100, 'lookups_per_day' => 7]));
        self::assertSame([3, 10, 5, 100, 7], array_map(static fn (string $policy): int => $sms->limiter($policy, 'x')->consume(0)->getLimit(), ['sms_number', 'sms_client', 'sms_account', 'sms_site', 'sms_lookup']));
        self::assertSame(1, RateLimiters::secondsUntil(new \DateTimeImmutable('-1 hour')));
        self::assertGreaterThanOrEqual(599, RateLimiters::secondsUntil(new \DateTimeImmutable('+10 minutes')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no rate limit policy "upload".');

        $limiters->limiter('upload', 'x');
    }
}
