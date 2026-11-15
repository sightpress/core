<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Finds out who is making a request. The module that provides sign-in registers one as a site service;
 * {@see AuthenticationMiddleware} calls it on every request to a site.
 */
interface Authenticator
{
    /**
     * Returns the request with who is signed in (and what they may do) on it.
     */
    public function authenticate(ServerRequestInterface $request): ServerRequestInterface;

    /**
     * Lets the authenticator add to the response, for example a renewed sign-in cookie.
     */
    public function respond(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
