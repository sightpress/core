<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Page\Contract\PublicPages;

/**
 * A stand-in for the public page router (C-6.2) in tests: renders the blocks of the published page at the request's
 * path for the visitor.
 */
final readonly class PageBlocksHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublicPages $pages,
        private BlockContexts $contexts,
        private BlockRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->pages->findByUrl($request->getUri()->getPath());
        if ($page === null) {
            return new Response(404);
        }
        $context = $this->contexts->create($page->locale, 'https://' . $request->getUri()->getHost(), $request);

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'], $this->renderer->html($page->blocks, $context));
    }
}
