<?php

declare(strict_types=1);

namespace SightPress\Core\Http\RateLimit;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Error\HttpTooManyRequestsException;

/**
 * Limits state-changing requests per client address with the `write` policy. Over the limit, it answers 429 with
 * `Retry-After`, and the error page says when to try again. The parts of a large file have their own `upload_part`
 * policy: one file is hundreds of requests, which must not use up the limit for everything else.
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    private const array STATE_CHANGING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const string UPLOAD_PART = '#^/admin/media/uploads/[0-9a-f]{32}$#';

    public function __construct(
        private RateLimiters $limiters,
        private ClockInterface $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::STATE_CHANGING, true)) {
            $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $policy = strtoupper($request->getMethod()) === 'PATCH' && preg_match(self::UPLOAD_PART, $request->getUri()->getPath()) === 1
                ? 'upload_part'
                : 'write';
            $limit = $this->limiters->limiter($policy, is_string($address) ? $address : 'unknown')->consume();

            if (!$limit->isAccepted()) {
                throw new HttpTooManyRequestsException(
                    $request,
                    retryAfter: max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
                );
            }
        }

        return $handler->handle($request);
    }
}
