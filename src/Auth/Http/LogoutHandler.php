<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Auth\RememberTokens;
use SightPress\Core\Auth\SignIn;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Rbac\Contract\Actor;

/**
 * `/logout`: GET asks to confirm with a button (signing out changes state, so it is a POST with a CSRF token);
 * POST signs out, forgets the "keep me signed in" cookie and goes to the sign-in page.
 */
final readonly class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthPages $pages,
        private SignIn $signIn,
        private RememberTokens $rememberTokens,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return Actor::fromRequest($request)->isSignedIn()
                ? $this->pages->page($request, 'logout.html.twig')
                : $this->pages->redirect('/login');
        }

        $this->signIn->end($request);
        Flash::add(Session::fromRequest($request), 'You have signed out.');

        return $this->pages->redirect('/login')
            ->withAddedHeader('Set-Cookie', $this->rememberTokens->forget($request));
    }
}
