<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Media\MediaUrlSigner;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\SiteContext;

/**
 * On a members-only site ({@see SiteVisibility}), sends anonymous visitors to sign in, then back to the page they
 * asked for (`/login?return=…`, a path on this site only). Signing in, signing up, the code and password pages, the
 * site's files (styles, scripts, icons, logo) and `robots.txt` stay open; everything else — pages, their Markdown,
 * `sitemap.xml`, `llms.txt`, media, avatars — waits for a signed-in account. Public sites aren't touched.
 */
final readonly class MembersOnlyMiddleware implements MiddlewareInterface
{
    /** Paths open to anonymous visitors on a members-only site: exact, or as a prefix when they end with `/`. */
    public const array OPEN = [
        '/login', '/login/', '/logout', '/register', '/register/', '/robots.txt', '/assets/', '/branding/',
        '/favicon.ico', '/apple-touch-icon.png', '/icon.svg', '/icon-192.png', '/icon-512.png', '/site.webmanifest',
    ];

    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = SiteContext::fromRequest($request)?->container;
        if (
            $container === null
            || Actor::fromRequest($request)->isSignedIn()
            || self::isOpen($request->getUri()->getPath())
            || self::isSignedMedia($request, $container)
            || !$container->has(SiteSettings::class)
            || !SiteVisibility::isMembersOnly(Services::get($container, SiteSettings::class))
        ) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(302)
            ->withHeader('Location', self::signIn($request))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * A signed file address is its own proof, and a CDN fetching it has no session. The file handler checks the
     * signature against the exact file and width; this only lets a request that carries a valid one get there.
     */
    private static function isSignedMedia(ServerRequestInterface $request, ContainerInterface $container): bool
    {
        if (preg_match('#^/media/([0-9a-f]{32})/(?:(\d{1,5})w/)?[^/]+$#', $request->getUri()->getPath(), $match) !== 1
            || !$container->has(MediaUrlSigner::class)) {
            return false;
        }

        return Services::get($container, MediaUrlSigner::class)->verifies(
            $match[1],
            ($match[2] ?? '') === '' ? null : (int) $match[2],
            $request->getQueryParams(),
        );
    }

    public static function isOpen(string $path): bool
    {
        foreach (self::OPEN as $open) {
            if ($path === $open || (str_ends_with($open, '/') && str_starts_with($path, $open))) {
                return true;
            }
        }

        return false;
    }

    /**
     * `/login`, with the page to go back to when it was a page someone opened (a GET).
     */
    public static function signIn(ServerRequestInterface $request): string
    {
        if ($request->getMethod() !== 'GET' && $request->getMethod() !== 'HEAD') {
            return '/login';
        }
        $uri = $request->getUri();
        $return = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

        return $return === '/' ? '/login' : '/login?' . http_build_query(['return' => $return]);
    }
}
