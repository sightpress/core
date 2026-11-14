<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Csrf;

use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Http\Session\Session;
use Slim\Csrf\Guard;

/**
 * CSRF tokens for one request, kept in the visitor's session. A token is only created — and so a session only
 * started — when a form asks for one. Tokens stay valid for the session (up to the last {@see STORAGE_LIMIT}), so
 * several open forms and the back button keep working.
 */
final class CsrfTokens
{
    public const string PREFIX = 'csrf';

    public const string SESSION_KEY = '_csrf';

    public const int STORAGE_LIMIT = 100;

    private ?Guard $guard = null;

    private ?CsrfToken $token = null;

    public function __construct(
        private readonly Session $session,
        private readonly ResponseFactoryInterface $responses,
    ) {
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $tokens = $request->getAttribute(self::class);
        if (!$tokens instanceof self) {
            throw new LogicException('There are no CSRF tokens: the CSRF middleware must run before this code.');
        }

        return $tokens;
    }

    /**
     * The token to put in the form being rendered (the same one for every form on the page).
     */
    public function token(): CsrfToken
    {
        if ($this->token === null) {
            $guard = $this->guard();
            $pair = $guard->generateToken();
            $name = $pair[$guard->getTokenNameKey()] ?? '';
            $value = $pair[$guard->getTokenValueKey()] ?? '';
            $this->token = new CsrfToken(
                $guard->getTokenNameKey(),
                is_string($name) ? $name : '',
                $guard->getTokenValueKey(),
                is_string($value) ? $value : '',
            );
        }

        return $this->token;
    }

    public function guard(): Guard
    {
        if ($this->guard === null) {
            $storage = &$this->session->bag(self::SESSION_KEY);
            $this->guard = new Guard(
                $this->responses,
                self::PREFIX,
                $storage,
                static function (ServerRequestInterface $request): never {
                    throw new CsrfTokenMismatchException($request);
                },
                self::STORAGE_LIMIT,
                32,
                true,
            );
        }

        return $this->guard;
    }
}
