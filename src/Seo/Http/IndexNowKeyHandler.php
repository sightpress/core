<?php

declare(strict_types=1);

namespace SightPress\Core\Seo\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Seo\IndexNow;
use Slim\Exception\HttpNotFoundException;

/**
 * `/indexnow-key.txt`: the site's IndexNow key, so search engines can check a ping came from the site. Not found
 * while IndexNow is off.
 */
final readonly class IndexNowKeyHandler implements RequestHandlerInterface
{
    public function __construct(
        private IndexNow $indexNow,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->indexNow->isOn()) {
            throw new HttpNotFoundException($request);
        }
        $response = $this->responses->createResponse()
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Cache-Control', 'public, max-age=3600');
        $response->getBody()->write($this->indexNow->key());

        return $response;
    }
}
