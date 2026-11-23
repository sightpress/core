<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Theme\ThemeAssets;
use Slim\Exception\HttpNotFoundException;

/**
 * `/assets/{path}`: sends a theme file with an `ETag`. URLs built by `asset()` carry a version (`?v=`), so those
 * are cached for a year; others for five minutes. SVG files get a policy that lets them run nothing.
 */
final readonly class ThemeAssetHandler implements RequestHandlerInterface
{
    public const string PATH_ATTRIBUTE = 'path';

    public function __construct(
        private ThemeAssets $assets,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute(self::PATH_ATTRIBUTE);
        $asset = is_string($path) ? $this->assets->find($path) : null;
        if ($asset === null) {
            throw new HttpNotFoundException($request);
        }

        $etag = $asset->etag();
        $versioned = ($request->getQueryParams()['v'] ?? '') !== '';
        $response = $this->responses->createResponse()
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        if (str_starts_with($asset->contentType, 'image/svg')) {
            $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }

        if ($asset->contentType === 'font/woff2') {
            // Browsers fetch fonts from another host (a CDN) only with this header.
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        }

        if (in_array($etag, array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }

        $response = $response
            ->withHeader('Content-Type', $asset->contentType)
            ->withHeader('Content-Length', (string) strlen($asset->contents));

        return $request->getMethod() === 'HEAD'
            ? $response
            : $response->withBody($this->streams->createStream($asset->contents));
    }
}
