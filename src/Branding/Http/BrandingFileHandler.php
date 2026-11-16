<?php

declare(strict_types=1);

namespace SightPress\Core\Branding\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Branding\BrandingService;
use Slim\Exception\HttpNotFoundException;

/**
 * The site's icons at the addresses browsers look for (`/favicon.ico`, `/apple-touch-icon.png`, `/icon.svg`,
 * `/icon-192.png`, `/icon-512.png`) and its logo and share image under `/branding/`. With the current version in
 * `?v=` a file may be kept for a year; without it, for an hour. SVG files get a Content-Security-Policy that lets
 * them run nothing and load nothing.
 */
final readonly class BrandingFileHandler implements RequestHandlerInterface
{
    public const array ROOT_FILES = ['favicon.ico', 'apple-touch-icon.png', 'icon.svg', 'icon-192.png', 'icon-512.png'];

    public const array BRANDING_FILES = ['logo.svg', 'logo.png', 'wordmark.svg', 'share.png', 'share.jpg'];

    public const string SVG_POLICY = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    private const array TYPES = ['ico' => 'image/x-icon', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg'];

    public function __construct(
        private BrandingService $branding,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $file = basename($path);
        $allowed = str_starts_with($path, '/branding/') ? self::BRANDING_FILES : self::ROOT_FILES;
        $version = $this->branding->state()->version;
        if ($version === '') {
            $version = $this->branding->regenerate()->version;
        }
        $contents = in_array($file, $allowed, true) ? $this->branding->read($file) : null;
        if ($contents === null) {
            throw new HttpNotFoundException($request);
        }

        $etag = '"' . $version . '-' . $file . '"';
        $query = $request->getQueryParams();
        $response = $this->responses->createResponse()
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', ($query['v'] ?? null) === $version ? 'public, max-age=31536000, immutable' : 'public, max-age=3600')
            ->withHeader('Content-Security-Policy', str_ends_with($file, '.svg') ? self::SVG_POLICY : "default-src 'none'; sandbox")
            ->withHeader('X-Content-Type-Options', 'nosniff');
        if (in_array($etag, array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream')
            ->withHeader('Content-Length', (string) strlen($contents));
    }
}
