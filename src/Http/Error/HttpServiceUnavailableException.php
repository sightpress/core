<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Error;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

/**
 * 503: the service (or one site, for example a disabled one) is unavailable for now.
 */
final class HttpServiceUnavailableException extends HttpException implements RetryAfter
{
    protected string $title = '503 Service Unavailable';

    protected string $description = 'The service is unavailable for now. Try again later.';

    /**
     * @param positive-int|null $retryAfter seconds until the client may try again
     */
    public function __construct(
        ServerRequestInterface $request,
        ?string $message = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($request, $message ?? 'Service unavailable.', 503, $previous);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
