<?php

declare(strict_types=1);

namespace SightPress\Core\Seo\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Seo\SeoFiles;
use SightPress\Core\Tenant\Site;

/**
 * `/sitemap.xml` and `/robots.txt`, always for the site's first domain.
 */
final readonly class SeoFileHandler implements RequestHandlerInterface
{
    public const string SITEMAP = '/sitemap.xml';

    public const string ROBOTS = '/robots.txt';

    public function __construct(
        private SeoFiles $files,
        private Site $site,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->withHost($this->site->domains[0] ?? $uri->getHost())->getAuthority();
        $sitemap = $uri->getPath() === self::SITEMAP;
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', $sitemap ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
        $response->getBody()->write($sitemap ? $this->files->sitemap($base) : $this->files->robots($base));

        return $response;
    }
}
