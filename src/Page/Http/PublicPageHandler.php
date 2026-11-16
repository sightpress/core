<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * The public site: `/` is the home page, `/{path}` the page at that address (`/pt-pt/…` for other locales), in the
 * theme layout the page uses. Only pages the visitor may view are shown (published, and public or the visitor is
 * signed in); anything else is a 404, so nothing is given away about drafts. Addresses that aren't the page's own
 * are moved permanently: `/about/` → `/about`, the home page's own path → `/`, any of the site's other domains → its
 * first one. Other modules add menus, `<head>` tags
 * and more through the {@see PublicPageRendering} event.
 *
 * Caching: a page that holds nothing personal (the visitor isn't signed in and the page left nothing in the session,
 * as a form does) may be kept by browsers and shared caches for {@see MAX_AGE} seconds; everything else is private.
 * Every page has an `ETag`, and a matching `If-None-Match` answers 304.
 */
final readonly class PublicPageHandler implements RequestHandlerInterface
{
    public const int MAX_AGE = 300;

    public function __construct(
        private PageAddresses $addresses,
        private Authorizer $authorizer,
        private BlockContexts $contexts,
        private BlockRenderer $renderer,
        private TemplateRenderer $templates,
        private Environment $twig,
        private Site $site,
        private ResponseFactoryInterface $responses,
        private EventDispatcherInterface $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $host = $this->site->domains[0] ?? $uri->getHost();
        if ($path !== '/' && str_ends_with($path, '/') || strtolower($uri->getHost()) !== $host) {
            return $this->moved($uri->withHost($host)->withPath($path === '/' ? '/' : '/' . trim($path, '/'))->__toString());
        }

        $translation = $this->addresses->find($path);
        $actor = Actor::fromRequest($request);
        if ($translation === null || !$this->authorizer->can($actor, PageAction::View->value, $translation->page())) {
            throw new HttpNotFoundException($request);
        }
        $url = $this->addresses->url($translation);
        if (rawurldecode($path) !== $url) {
            return $this->moved($uri->withPath($url)->__toString());
        }

        $page = $translation->page();
        $baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();
        $context = $this->contexts->create($translation->locale(), $baseUrl, $request);
        $content = $this->renderer->html($translation->blocks(), $context);
        $rendering = new PublicPageRendering(
            $request,
            (int) $page->id(),
            $translation->locale(),
            $url,
            $baseUrl,
            $translation->title(),
            $translation->summary(),
            $translation->description(),
            array_values(array_diff(array_map(intval(...), array_filter(explode('/', $page->treePath()), static fn (string $id): bool => $id !== '')), [$page->id()])),
            $this->addresses->isHome($page),
            $page->isPublic(),
            $translation->updatedAt(),
            $translation->socialImage(),
            $translation->seoTitle(),
            $translation->isNoindex(),
            $translation->canonicalUrl(),
            $translation->blocks(),
            $content,
        );
        $this->events->dispatch($rendering);
        $layout = 'layouts/' . $page->layout() . '.html.twig';
        $body = $this->templates->render($request, '@page/public.html.twig', [
            ...$rendering->variables(),
            'head' => $rendering->head(),
            'layout_template' => $this->twig->getLoader()->exists($layout) ? $layout : 'layouts/default.html.twig',
            'title' => $translation->title(),
            'lead' => $translation->summary(),
            'locale' => $translation->locale(),
            'site_name' => $this->site->name,
            'content' => $content,
            'updated_at' => $translation->updatedAt(),
        ]);

        $personal = $actor->isSignedIn() || Session::fromRequest($request)->all() !== [];
        $etag = '"' . hash('xxh128', $body) . '"';
        $response = $this->responses->createResponse()
            ->withHeader('Content-Language', $translation->locale())
            ->withHeader('Link', sprintf('<%s>; rel="canonical"', $rendering->canonicalUrl()))
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $personal ? 'private, no-cache' : 'public, max-age=' . self::MAX_AGE);
        if ($rendering->robots() !== null) {
            $response = $response->withHeader('X-Robots-Tag', $rendering->robots());
        }
        if (in_array($etag, array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function moved(string $location): ResponseInterface
    {
        return $this->responses->createResponse(301)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE);
    }
}
