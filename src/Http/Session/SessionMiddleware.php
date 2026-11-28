<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContext;

/**
 * Loads the visitor's {@see Session} from its cookie and saves it after the response, in the store of the site the
 * request is for (`session.store`); a request for no site uses the fallback store given here.
 *
 * The cookie is `HttpOnly`, `SameSite=Lax` and, over HTTPS, `Secure` with the `__Host-` prefix (so it is bound to
 * this host and path). It lasts until the browser closes; the store ends idle sessions. A session is only saved
 * — and a cookie only sent — once it holds data. Responses for a visitor with a session are `private, no-store`,
 * except those marked `public` that set no cookie.
 *
 * A request that didn't change the session only restarts its idle time. Requests of one visitor can overlap (a part of
 * a large file still arriving while the page reloads), and saving the copy it read would undo what the other stored,
 * such as a new form's CSRF token, or bring back a session that was signed out meanwhile.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'sightpress_session';

    public const string SECURE_COOKIE = '__Host-sightpress_session';

    private const string ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function __construct(private SessionStore $fallback)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = SiteContext::fromRequest($request);
        $store = $context !== null && $context->container->has(SessionStore::class)
            ? Services::get($context->container, SessionStore::class)
            : $this->fallback;
        $secure = $request->getUri()->getScheme() === 'https';
        $name = $secure ? self::SECURE_COOKIE : self::COOKIE;
        $cookie = $request->getCookieParams()[$name] ?? null;

        $session = new Session();
        $loaded = null;
        if (is_string($cookie) && preg_match(self::ID_PATTERN, $cookie) === 1) {
            $loaded = $store->read($cookie);
            $session = $loaded === null ? new Session() : new Session($cookie, $loaded);
        }

        $response = $handler->handle($request->withAttribute(Session::class, $session));

        $previous = $session->previousId();
        if ($previous !== null) {
            $store->delete($previous);
        }

        if ($session->all() === []) {
            if ($session->id() !== null) {
                $store->delete($session->id());
            }

            return is_string($cookie) ? $response->withAddedHeader('Set-Cookie', $this->expired($name, $secure)) : $response;
        }

        $id = $session->idOrNew();
        if ($id === $cookie && $session->all() === $loaded) {
            $store->touch($id);
        } else {
            $store->write($id, $session->all());
        }
        // A response that holds no personal data says so with `Cache-Control: public` (theme files, for example);
        // it keeps that header unless a new cookie is being set with it.
        if ($id !== $cookie || !str_contains($response->getHeaderLine('Cache-Control'), 'public')) {
            $response = $response->withHeader('Cache-Control', 'private, no-store');
        }

        return $id === $cookie ? $response : $response->withAddedHeader('Set-Cookie', sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Lax%s',
            $name,
            $id,
            $secure ? '; Secure' : '',
        ));
    }

    private function expired(string $name, bool $secure): string
    {
        return sprintf(
            '%s=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; SameSite=Lax%s',
            $name,
            $secure ? '; Secure' : '',
        );
    }
}
