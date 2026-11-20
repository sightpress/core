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
use SightPress\Core\Block\Contract\MediaTexts;
use SightPress\Core\Http\AcceptNegotiator;
use SightPress\Core\Http\Contract\AnsweredResource;
use SightPress\Core\Http\Security\MembersOnlyMiddleware;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Contract\MarkdownAddress;
use SightPress\Core\Page\Contract\MarkdownPages;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Page\Entity\PageRedirect;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageRedirects;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\LocaleFallback;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use Slim\Exception\HttpGoneException;
use Slim\Exception\HttpNotFoundException;
use SightPress\Core\Theme\Contract\ThemeLayouts;

/**
 * The public site: `/` is the home page, `/{path}` the page at that address (`/pt-pt/…` for other locales), in the
 * theme layout the page uses. Only pages the visitor may view are shown (published, and public or the visitor is
 * signed in), in a translation that isn't a draft of its own; anything else is a 404, so nothing is given away about
 * drafts. A published page for members only sends anonymous visitors to sign in and back; it, and every page of a
 * members-only site ({@see SiteVisibility}), is never indexable. Addresses that aren't the page's own
 * are moved permanently: `/about/` → `/about`, the home page's own path → `/`, any of the site's other domains → its
 * first one; old addresses of pages that moved lead to their new ones (redirects table, P-1). Pages in the trash, and
 * pages deleted for good, are gone (410). Other modules add menus, `<head>` tags
 * and more through the {@see PublicPageRendering} event.
 *
 * Every page is also there as Markdown, at `/{path}.md` (`/index.md` for the home page) or for requests that prefer
 * `text/markdown`; responses say `Vary: Accept` and carry the canonical address in a `Link` header.
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
        private ThemeLayouts $layouts,
        private Site $site,
        private ResponseFactoryInterface $responses,
        private EventDispatcherInterface $events,
        private MarkdownPages $documents,
        private AcceptNegotiator $negotiator,
        private TenantConfig $config,
        private MediaTexts $texts,
        private PageRedirects $redirects,
        private SiteSettings $settings,
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

        $prefixed = $this->localePrefix($path);
        if ($prefixed !== null) {
            return $this->moved($uri->withPath($prefixed)->__toString());
        }

        $markdownPath = str_ends_with($path, '.md');
        $pagePath = $markdownPath ? (string) preg_replace('#(^|/)index$#', '', substr($path, 0, -3)) : $path;
        $actor = Actor::fromRequest($request);
        if ($path === '/') {
            $negotiated = $this->negotiate($request, $actor);
            if ($negotiated !== null) {
                return $negotiated;
            }
        }
        [$locale, $localPath] = $this->addresses->split($pagePath === '' ? '/' : $pagePath);
        $translation = $this->addresses->findIn($locale, $localPath);
        $redirect = $translation === null ? $this->redirects->find($locale, rawurldecode($localPath)) : null;
        if ($redirect !== null) {
            return $this->redirected($request, $redirect, $markdownPath);
        }
        if ($translation !== null && !$actor->isSignedIn() && $translation->isPublished() && !$translation->page()->isPublic()) {
            return $this->signIn($request);
        }
        if ($translation === null || !$this->visible($translation, $actor)) {
            $fallback = $this->fallback($locale, $localPath, $translation, $actor);
            if ($fallback === null && $translation?->page()->state() === PageState::Trash) {
                throw new HttpGoneException($request);
            }
            if ($fallback === null) {
                throw new HttpNotFoundException($request);
            }

            return $this->found($request, $fallback, $markdownPath, $actor);
        }
        $url = $this->addresses->url($translation);
        $own = $markdownPath ? MarkdownAddress::of($url, $this->addresses->isHome($translation->page())) : $url;
        if (rawurldecode($path) !== $own) {
            return $this->moved($uri->withPath($own)->__toString());
        }

        $page = $translation->page();
        $baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();
        $blocks = $this->texts->fill($translation->blocks(), $translation->locale());
        $markdown = $markdownPath || $this->negotiator->prefersMarkdown($request);
        AnsweredResource::fromRequest($request)?->page((int) $page->id(), $translation->locale(), $markdown);
        if ($markdown) {
            return $this->markdown($request, $translation, $blocks, $baseUrl . $url, $baseUrl, $actor);
        }
        $context = $this->contexts->create($translation->locale(), $baseUrl, $request);
        $content = $this->renderer->html($blocks, $context);
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
            SiteVisibility::isOpenToEveryone($page->isPublic(), $this->settings),
            $translation->updatedAt(),
            $translation->socialImage(),
            $translation->seoTitle(),
            $translation->isNoindex(),
            $translation->canonicalUrl(),
            $blocks,
            $content,
        );
        $this->events->dispatch($rendering);
        // The site's own words (menus, buttons, the language switcher) are in the page's language.
        $own = $request->getAttribute(ViewerPreferences::class);
        $timezone = $own instanceof ViewerPreferences && $own->timezone !== null && $own->timezone !== '' ? $own->timezone : $this->site->defaultTimezone;
        $request = $request->withAttribute(Viewer::class, new Viewer($translation->locale() === '' ? $this->site->defaultLocale : $translation->locale(), $timezone));
        $body = $this->templates->render($request, '@page/public.html.twig', [
            ...$rendering->variables(),
            'head' => $rendering->head(),
            'layout_template' => $this->layouts->template($page->layout()),
            'title' => $translation->title(),
            'lead' => $translation->summary(),
            'locale' => $translation->locale(),
            'site_name' => $this->site->name,
            'content' => $content,
            'updated_at' => $translation->updatedAt(),
            'home_url' => $this->homeUrl($translation->locale()),
            'languages' => $this->languages($translation),
        ]);

        $personal = $actor->isSignedIn() || Session::fromRequest($request)->all() !== [];

        return $this->respond($request, $body, 'text/html; charset=utf-8', $translation->locale(), $rendering->canonicalUrl(), $rendering->robots(), $personal);
    }

    /**
     * The home page in a language when it is published in it, otherwise the home page in the default language.
     */
    private function homeUrl(string $locale): string
    {
        $home = $this->addresses->home()?->translation($locale);

        return $home !== null && $home->isPublished() ? $this->addresses->homeUrl($locale) : '/';
    }

    /**
     * The page in each of the site's languages it is published in, for the language switcher: each language named in
     * its own language. Empty when there is only one.
     *
     * @return list<array{code: string, name: string, url: string, current: bool}>
     */
    private function languages(PageTranslation $current): array
    {
        $page = $current->page();
        $languages = [];
        foreach ($this->site->locales as $locale) {
            $translation = $page->translation($locale);
            if ($translation !== null && ($translation === $current || $translation->isPublished())) {
                $languages[] = ['code' => $locale, 'name' => LocaleCode::ownName($locale), 'url' => $this->addresses->url($translation), 'current' => $translation === $current];
            }
        }

        return count($languages) > 1 ? $languages : [];
    }

    /**
     * Whether the visitor may read this translation: they may see its page, and it isn't a draft of its own (those are
     * only there for the people who can change the page).
     */
    private function visible(PageTranslation $translation, Actor $actor): bool
    {
        $page = $translation->page();

        return $this->authorizer->can($actor, PageAction::View->value, $page)
            && (!$translation->isDraft() || $this->authorizer->can($actor, PageAction::Edit->value, $page));
    }

    /**
     * The translation to send someone to when the page isn't there in the language they asked for
     * ({@see LocaleFallback}): the same page in the closest of the site's languages. The page is the one at the
     * address in that language or, when there is none, at the same address in another of the site's languages
     * (whose translation in the asked language, at its own address, comes first).
     */
    private function fallback(string $locale, string $path, ?PageTranslation $asked, Actor $actor): ?PageTranslation
    {
        $chain = LocaleFallback::chain($locale, $this->site->locales, $this->site->defaultLocale);
        $others = array_values(array_diff($chain, [$locale]));
        $page = $asked?->page();
        foreach ($others as $other) {
            $page ??= $this->addresses->findIn($other, $path)?->page();
        }
        if ($page === null) {
            return null;
        }
        // Found at another language's address: the asked language's own address comes first, if it has one.
        foreach ($asked === null ? $chain : $others as $other) {
            $translation = $page->translation($other);
            if ($translation !== null && $this->visible($translation, $actor)) {
                return $translation;
            }
        }

        return null;
    }

    /**
     * A temporary redirect (302) to the page in another language: never canonical, so it says the language it leads
     * to and every real translation of the page in `Link` headers, with `x-default`.
     */
    private function found(ServerRequestInterface $request, PageTranslation $target, bool $markdown, Actor $actor): ResponseInterface
    {
        $page = $target->page();
        $home = $this->addresses->isHome($page);
        $url = $this->addresses->url($target);
        $uri = $request->getUri();
        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        $response = $this->responses->createResponse(302)
            ->withHeader('Location', $uri->withPath($markdown ? MarkdownAddress::of($url, $home) : $url)->withQuery('')->__toString())
            ->withHeader('Content-Language', $target->locale())
            ->withHeader('Cache-Control', $actor->isSignedIn() ? 'private, no-cache' : 'public, max-age=' . self::MAX_AGE);
        $links = [];
        foreach ($this->site->locales as $locale) {
            $translation = $page->translation($locale);
            if ($translation !== null && $translation->isPublished() && SiteVisibility::isOpenToEveryone($page->isPublic(), $this->settings)) {
                $links[$locale] = sprintf('<%s%s>; rel="alternate"; hreflang="%s"', $base, $this->addresses->url($translation), $locale);
            }
        }
        if (isset($links[$this->site->defaultLocale])) {
            $default = $page->translation($this->site->defaultLocale);
            $links['x-default'] = sprintf('<%s%s>; rel="alternate"; hreflang="x-default"', $base, $default === null ? '/' : $this->addresses->url($default));
        }

        return $links === [] ? $response : $response->withHeader('Link', array_values($links));
    }

    /**
     * On `/` only, the home page in the visitor's language (ADR-0007): a signed-in person's own language, otherwise
     * the best match of `Accept-Language` among the site's languages, with the same fallback rules. Someone who
     * followed a link on the site itself (to the home page in the default language, say) is never sent away.
     */
    private function negotiate(ServerRequestInterface $request, Actor $actor): ?ResponseInterface
    {
        if (count($this->site->locales) < 2 || $this->fromThisSite($request)) {
            return null;
        }
        $preferences = $request->getAttribute(ViewerPreferences::class);
        $own = $preferences instanceof ViewerPreferences ? $preferences->locale : null;
        $wanted = $own !== null && $own !== '' ? $own : LocaleFallback::negotiate($request->getHeaderLine('Accept-Language'), $this->site->locales);
        $home = $this->addresses->home();
        if ($wanted === null || $home === null) {
            return null;
        }
        foreach (LocaleFallback::chain($wanted, $this->site->locales, $this->site->defaultLocale) as $locale) {
            $translation = $home->translation($locale);
            if ($translation !== null && $this->visible($translation, $actor)) {
                if ($locale === $this->site->defaultLocale) {
                    return null;
                }

                return $this->responses->createResponse(302)
                    ->withHeader('Location', $request->getUri()->withPath($this->addresses->homeUrl($locale))->__toString())
                    ->withHeader('Content-Language', $locale)
                    ->withHeader('Vary', 'Accept-Language, Cookie')
                    ->withHeader('Cache-Control', 'private, no-cache');
            }
        }

        return null;
    }

    private function fromThisSite(ServerRequestInterface $request): bool
    {
        $referer = parse_url($request->getHeaderLine('Referer'), PHP_URL_HOST);

        return is_string($referer) && strtolower($referer) === strtolower($request->getUri()->getHost());
    }

    /**
     * The address with the site's way of writing its language part, when it is written another way: lower case
     * (`/PT-PT/sobre` → `/pt-pt/sobre`) and none for the default language (`/en-gb/about` → `/about`). Null when it
     * is already right.
     */
    private function localePrefix(string $path): ?string
    {
        $segments = explode('/', ltrim($path, '/'), 2);
        foreach ($this->site->locales as $locale) {
            if (strtolower($segments[0]) !== strtolower($locale)) {
                continue;
            }
            $prefix = $this->addresses->prefixOf($locale);
            if ($prefix === '/' . $segments[0]) {
                return null;
            }
            $rest = isset($segments[1]) && $segments[1] !== '' ? '/' . $segments[1] : '';

            return ($prefix . $rest) === '' ? '/' : $prefix . $rest;
        }

        return null;
    }

    /**
     * The page as Markdown (`/{path}.md`, or `Accept: text/markdown`), for readers that prefer text. Its canonical
     * address is the HTML page's; a page search engines shouldn't list says so in `X-Robots-Tag`.
     */
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    private function markdown(ServerRequestInterface $request, PageTranslation $translation, array $blocks, string $address, string $baseUrl, Actor $actor): ResponseInterface
    {
        $canonical = $translation->canonicalUrl() ?? $address;
        $body = $this->documents->document($translation->locale(), $translation->title(), $translation->summary(), $blocks, $canonical, $translation->updatedAt(), $baseUrl);
        $indexable = SiteVisibility::isOpenToEveryone($translation->page()->isPublic(), $this->settings) && !$translation->isNoindex();
        $robots = match (true) {
            !$this->config->environment->isProduction() => 'noindex, nofollow',
            !$indexable => 'noindex',
            default => null,
        };

        return $this->respond($request, $body, 'text/markdown; charset=utf-8', $translation->locale(), $canonical, $robots, $actor->isSignedIn());
    }

    private function respond(ServerRequestInterface $request, string $body, string $type, string $locale, string $canonical, ?string $robots, bool $personal): ResponseInterface
    {
        $etag = '"' . hash('xxh128', $type . $body) . '"';
        $response = $this->responses->createResponse()
            ->withHeader('Content-Language', $locale)
            ->withHeader('Vary', $request->getUri()->getPath() === '/' && count($this->site->locales) > 1 ? 'Accept, Accept-Language' : 'Accept')
            ->withHeader('Link', sprintf('<%s>; rel="canonical"', $canonical))
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', $personal ? 'private, no-cache' : 'public, max-age=' . self::MAX_AGE);
        if ($robots !== null) {
            $response = $response->withHeader('X-Robots-Tag', $robots);
        }
        if (in_array($etag, array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match'))), true)) {
            return $response->withStatus(304);
        }
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', $type);
    }

    /**
     * An old address: a permanent redirect (301) to where the page is now, or "gone" (410) when it was deleted.
     *
     * @throws HttpGoneException
     */
    private function redirected(ServerRequestInterface $request, PageRedirect $redirect, bool $markdown): ResponseInterface
    {
        $target = $redirect->target() ?? throw new HttpGoneException($request);
        $translation = $this->addresses->findIn($redirect->locale(), $target);
        $prefix = $this->addresses->prefixOf($redirect->locale());
        $url = $translation === null ? $prefix . '/' . $target : $this->addresses->url($translation);
        $home = $translation !== null && $this->addresses->isHome($translation->page());

        return $this->moved($request->getUri()->withPath($markdown ? MarkdownAddress::of($url, $home) : $url)->__toString());
    }

    /**
     * A page for members only, asked for by someone who isn't signed in: sign in first, then come back (D24). The
     * page's title and content are never sent.
     */
    private function signIn(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', MembersOnlyMiddleware::signIn($request))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function moved(string $location): ResponseInterface
    {
        return $this->responses->createResponse(301)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE);
    }
}
