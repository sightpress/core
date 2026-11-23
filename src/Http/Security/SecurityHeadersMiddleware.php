<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRepository;

/**
 * Adds the security headers to every response: a Content Security Policy with a fresh nonce per request, HSTS over
 * HTTPS, and headers that stop sniffing, framing, cross-origin leaks and unused browser features. A header the
 * handler already set (for example the stricter policy of error pages) is kept.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const string PERMISSIONS_POLICY = 'accelerometer=(), browsing-topics=(), camera=(), geolocation=(), '
        . 'gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    public function __construct(private ?SiteRepository $sites = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = CspNonce::generate();
        $https = $request->getUri()->getScheme() === 'https';

        $response = $handler->handle($request->withAttribute(CspNonce::class, $nonce));

        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce, $https, $this->cdn($request)),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * The site's CDN, which may serve its files. Read here because this middleware runs before the site is resolved.
     */
    private function cdn(ServerRequestInterface $request): string
    {
        try {
            $cdn = $this->sites?->findByDomain($request->getUri()->getHost())?->storage->cdnUrl;
        } catch (SiteRegistryException) {
            // A broken registry already has its own error page; the headers must still go out.
            return '';
        }

        return $cdn === null ? '' : ' ' . $cdn;
    }

    private function contentSecurityPolicy(CspNonce $nonce, bool $https, string $cdn): string
    {
        $policy = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce->value}'",
            "style-src 'self'{$cdn} 'nonce-{$nonce->value}'",
            "img-src 'self'{$cdn} data:",
            "font-src 'self'{$cdn}",
            "connect-src 'self'",
            "media-src 'self'{$cdn}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];
        if ($https) {
            $policy[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $policy);
    }
}
