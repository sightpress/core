<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Http\RateLimit\RateLimiters;
use SightPress\Core\Page\Contract\PublicScreenRendering;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Search\Contract\SearchPage;
use SightPress\Core\Search\SiteSearch;
use SightPress\Core\Template\Message;
use SightPress\Core\Template\TemplateRenderer;
use SightPress\Core\Template\Translations;
use SightPress\Core\Template\Viewer;
use SightPress\Core\Template\ViewerPreferences;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Search\SearchHit;
use SightPress\Module\Search\SearchResults;
use Slim\Exception\HttpNotFoundException;

/**
 * The results page of site search (SR-2): `/search?q=…&page=…` in the site's main language, `/pt-pt/search?q=…` in
 * the others, in the theme's `search` layout with the site's menus. Its title and `<h1>` say what was searched for
 * and how many results there are; each result links to its page with its breadcrumb and an extract where the
 * matched words are marked. Pages for members only are found by people signed in to the site. Searches are
 * rate-limited per visitor (a 429 page that says when to try again). The page is never listed by search engines
 * (`noindex, follow`) and has no canonical address.
 */
final readonly class SearchHandler implements RequestHandlerInterface
{
    public const string PATH = SearchPage::PATH;

    public const string LOCALE_PATH = '/{locale:[a-z]{2,3}(?:-[a-z0-9]{2,8})*}' . SearchPage::PATH;

    public const int PER_PAGE = 10;

    /** Pages of results after this one are not offered. */
    public const int MAX_PAGE = 100;

    public const string ROBOTS = 'noindex, follow';

    public function __construct(
        private SiteSearch $search,
        private TemplateRenderer $templates,
        private Translations $translations,
        private EventDispatcherInterface $events,
        private ResponseFactoryInterface $responses,
        private RateLimiters $limiters,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $locale = $this->locale($uri->getPath());
        if ($locale === null) {
            throw new HttpNotFoundException($request);
        }
        $action = SearchPage::url($locale, $this->site->defaultLocale);
        if ($uri->getPath() !== $action) {
            return $this->responses->createResponse(301)->withHeader('Location', $uri->withPath($action)->__toString());
        }
        $params = $request->getQueryParams();
        $text = is_string($params[SearchPage::QUERY] ?? null) ? $params[SearchPage::QUERY] : '';
        $page = is_string($params[SearchPage::PAGE] ?? null) && preg_match('/^[1-9]\d{0,2}$/', $params[SearchPage::PAGE]) === 1 ? max(1, min((int) $params[SearchPage::PAGE], self::MAX_PAGE)) : 1;
        $query = SiteSearch::query($text, $locale, Actor::fromRequest($request)->isSignedIn(), $page, self::PER_PAGE);

        $results = null;
        $retryAfter = null;
        if ($query !== null) {
            $address = $request->getServerParams()['REMOTE_ADDR'] ?? null;
            $limit = $this->limiters->limiter('search', is_string($address) ? $address : 'unknown')->consume();
            if ($limit->isAccepted()) {
                $results = $this->search->search($query);
            } else {
                $retryAfter = RateLimiters::secondsUntil($limit->getRetryAfter());
            }
        }

        // The site's own words (menus, buttons, the language switcher) are in the language searched.
        $own = $request->getAttribute(ViewerPreferences::class);
        $timezone = $own instanceof ViewerPreferences && $own->timezone !== null && $own->timezone !== '' ? $own->timezone : $this->site->defaultTimezone;
        $request = $request->withAttribute(Viewer::class, new Viewer($locale, $timezone));
        $title = $this->translations->translate(self::title($query->text ?? '', $results, $retryAfter !== null), [], $locale);
        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        $screen = new PublicScreenRendering($request, $locale, $action, $base, $title);
        $this->events->dispatch($screen);

        $body = $this->templates->render($request, '@search/results.html.twig', [
            ...$screen->variables(),
            'head' => $screen->head(),
            'robots' => self::ROBOTS,
            'title' => $title,
            'document_title' => $title . ($results !== null && $results->pageCount() > 1 ? ' (' . $this->translations->translate(new Message('page {page} of {pages}', ['page' => $results->query->page, 'pages' => $results->pageCount()]), [], $locale) . ')' : '') . ' - ' . $this->site->name,
            'locale' => $locale,
            'site_name' => $this->site->name,
            'home_url' => $locale === $this->site->defaultLocale ? '/' : '/' . strtolower($locale),
            'action' => $action,
            'search_action' => $action,
            'query' => $query->text ?? '',
            'state' => match (true) {
                $retryAfter !== null => 'busy',
                $results === null => 'empty',
                $results->isEmpty() => 'none',
                default => 'results',
            },
            'retry_minutes' => $retryAfter === null ? null : (int) ceil($retryAfter / 60),
            'total' => $results->total ?? 0,
            'matched_all' => $results->matchedAllTerms ?? true,
            'first' => $results === null ? 1 : $results->query->offset() + 1,
            'results' => $results === null ? [] : array_map(self::result(...), $results->hits),
            'pagination' => $results === null ? null : self::pagination($results, $action),
            'languages' => $this->languages($locale, $query->text ?? ''),
            'language_name' => LocaleCode::ownName($locale),
        ]);

        $response = $this->responses->createResponse($retryAfter === null ? 200 : 429)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Language', $locale)
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withHeader('X-Robots-Tag', self::ROBOTS);
        if ($retryAfter !== null) {
            $response = $response->withHeader('Retry-After', (string) $retryAfter);
        }
        $response->getBody()->write($body);

        return $response;
    }

    /**
     * The site locale an address is for: the main one for `/search`, another one for `/pt-pt/search`; null when
     * the site isn't written in it.
     *
     * @return non-empty-string|null
     */
    private function locale(string $path): ?string
    {
        if ($path === self::PATH) {
            return $this->site->defaultLocale;
        }
        $prefix = substr($path, 1, -strlen(self::PATH));
        foreach ($this->site->locales as $locale) {
            if (strtolower($locale) === $prefix) {
                return $locale;
            }
        }

        return null;
    }

    private static function title(string $text, ?SearchResults $results, bool $busy): Message
    {
        return match (true) {
            $busy => new Message('Too many searches'),
            $results === null => new Message('Search'),
            $results->isEmpty() => new Message('No results for “{query}”', ['query' => $text]),
            default => new Message('{count, plural, one {# result} other {# results}} for “{query}”', ['count' => $results->total, 'query' => $text]),
        };
    }

    /**
     * @return array{title: string, url: string, excerpt: string, locale: string, trail: string, extract: list<array{text: string, mark: bool}>}
     */
    private static function result(SearchHit $hit): array
    {
        $document = $hit->document;
        $extract = SearchExtract::of($document->summary, $document->body, $hit->matchedWords);

        return [
            'title' => $document->title,
            'url' => $document->path,
            'excerpt' => implode('', array_map(static fn (array $piece): string => $piece['text'], $extract)),
            'locale' => $document->locale,
            'trail' => implode(' › ', $document->breadcrumb),
            'extract' => $extract,
        ];
    }

    /**
     * @return array{current: int, pages: int, previous: ?string, next: ?string, pattern: string}
     */
    private static function pagination(SearchResults $results, string $action): array
    {
        $pages = min($results->pageCount(), self::MAX_PAGE);
        $current = $results->query->page;
        $url = static fn (int $page): string => $action . '?' . http_build_query([SearchPage::QUERY => $results->query->text] + ($page > 1 ? [SearchPage::PAGE => $page] : []), '', '&', PHP_QUERY_RFC3986);

        return [
            'current' => $current,
            'pages' => $pages,
            'previous' => $current > 1 ? $url($current - 1) : null,
            'next' => $current < $pages ? $url($current + 1) : null,
            // For the theme's pagination partial, which puts each page number in for %d.
            'pattern' => str_replace('%', '%%', $action . '?' . http_build_query([SearchPage::QUERY => $results->query->text], '', '&', PHP_QUERY_RFC3986)) . '&' . SearchPage::PAGE . '=%d',
        ];
    }

    /**
     * The same search in each of the site's languages, for the language switcher; empty when there is only one.
     *
     * @return list<array{code: string, name: string, url: string, current: bool}>
     */
    private function languages(string $current, string $text): array
    {
        if (count($this->site->locales) < 2) {
            return [];
        }
        $languages = [];
        foreach ($this->site->locales as $locale) {
            $url = SearchPage::url($locale, $this->site->defaultLocale);
            $languages[] = ['code' => $locale, 'name' => LocaleCode::ownName($locale), 'url' => $text === '' ? $url : $url . '?' . http_build_query([SearchPage::QUERY => $text], '', '&', PHP_QUERY_RFC3986), 'current' => $locale === $current];
        }

        return $languages;
    }
}
