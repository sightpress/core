<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpTooManyRequestsException as SlimTooManyRequests;
use Throwable;

/**
 * 429: the client sent too many requests (rate limit); says when it may try again.
 */
final class HttpTooManyRequestsException extends SlimTooManyRequests implements RetryAfter
{
    /**
     * @param positive-int|null $retryAfter seconds until the client may try again
     */
    public function __construct(
        ServerRequestInterface $request,
        ?string $message = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($request, $message, $previous);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
