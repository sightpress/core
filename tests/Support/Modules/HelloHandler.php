<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A route handler of a test module.
 */
final class HelloHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'hello');
    }
}
