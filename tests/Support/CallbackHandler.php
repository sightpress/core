<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A request handler for tests: runs a closure and remembers the last request it got.
 */
final class CallbackHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $callback
     */
    public function __construct(private readonly Closure $callback)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return ($this->callback)($request);
    }
}
