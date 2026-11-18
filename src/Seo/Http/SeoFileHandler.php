<?php

declare(strict_types=1);

namespace SightPress\Core\Seo\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use SightPress\Core\Seo\SeoFiles;
use SightPress\Core\Tenant\Site;

/**
 * `/sitemap.xml` (an index of `/sitemap-<locale>.xml` when the site has several languages), `/robots.txt`, `/llms.txt`
 * and `/llms-full.txt`, always for the site's first domain. The sitemap of a language the site doesn't have is a 404.
 */
final readonly class SeoFileHandler implements RequestHandlerInterface
{
    public const string SITEMAP = '/sitemap.xml';

    public const string ROBOTS = '/robots.txt';

    public const string LLMS = '/llms.txt';

    public const string LLMS_FULL = '/llms-full.txt';

    /** The sitemap of one language, `/sitemap-pt-pt.xml`, when the site has more than one. */
    public const string LOCALE_SITEMAP = '/sitemap-{locale:[a-z0-9-]+}.xml';

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
        $path = $uri->getPath();
        $locale = preg_match('#^/sitemap-([a-z0-9-]+)\.xml$#', $path, $match) === 1 ? $match[1] : null;
        $localeSitemap = $locale === null || count($this->site->locales) < 2 ? null : $this->files->localeSitemap($base, $locale);
        if ($locale !== null && $localeSitemap === null) {
            throw new HttpNotFoundException($request);
        }
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', $path === self::SITEMAP || $locale !== null ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
        $response->getBody()->write(match (true) {
            $localeSitemap !== null => $localeSitemap,
            $path === self::SITEMAP => $this->files->sitemap($base),
            $path === self::LLMS => $this->files->llms($base),
            $path === self::LLMS_FULL => $this->files->llmsFull($base),
            default => $this->files->robots($base),
        });

        return $response;
    }
}
