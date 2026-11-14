<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\Csrf\CsrfTokens;

/**
 * GET shows the form's CSRF fields as a query string; POST says it saved.
 */
final class FormHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'POST') {
            return new Response(200, [], 'saved');
        }

        $token = CsrfTokens::fromRequest($request)->token();

        return new Response(200, [], http_build_query([$token->nameKey => $token->name, $token->valueKey => $token->value]));
    }
}
