<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds the security headers to every response: a Content Security Policy with a fresh nonce per request, HSTS over
 * HTTPS, and headers that stop sniffing, framing, cross-origin leaks and unused browser features. A header the
 * handler already set (for example the stricter policy of error pages) is kept.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const string PERMISSIONS_POLICY = 'accelerometer=(), browsing-topics=(), camera=(), geolocation=(), '
        . 'gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = CspNonce::generate();
        $https = $request->getUri()->getScheme() === 'https';

        $response = $handler->handle($request->withAttribute(CspNonce::class, $nonce));

        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce, $https),
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

    private function contentSecurityPolicy(CspNonce $nonce, bool $https): string
    {
        $policy = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce->value}'",
            "style-src 'self' 'nonce-{$nonce->value}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self'",
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
