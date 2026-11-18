<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use SightPress\Core\Template\Message;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Entity\PageTranslation;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountDirectory;
use Slim\Exception\HttpForbiddenException;

/**
 * `/admin/pages`: every page the person may open, as a tree of nested lists in the site's order (the theme's
 * script adds a button to show and hide the pages under each one). Filters by state, by text in the title and
 * "only my pages" turn it into a flat list of the pages that match, each with the pages above it.
 */
final readonly class PageListHandler implements RequestHandlerInterface
{
    /** Permissions that let someone use the page screens. */
    public const array PERMISSIONS = ['page.edit.own', 'page.edit.any', 'page.publish'];

    public function __construct(
        private AdminScreens $screens,
        private PageRepository $pages,
        private PageAddresses $addresses,
        private Authorizer $authorizer,
        private AccountDirectory $accounts,
        private Site $site,
    ) {
    }

    public static function mayUse(Actor $actor): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if ($actor->has($permission)) {
                return true;
            }
        }

        return false;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!self::mayUse($actor)) {
            throw new HttpForbiddenException($request);
        }
        $query = $request->getQueryParams();
        $state = PageState::tryFrom(is_string($query['state'] ?? null) ? $query['state'] : '');
        $text = trim(is_string($query['q'] ?? null) ? $query['q'] : '');
        $mine = ($query['mine'] ?? null) === '1';
        $filtered = $state !== null || $text !== '' || $mine;

        $all = $this->pages->all();
        $names = $this->authorNames($all);
        $nodes = [];
        foreach ($all as $page) {
            $nodes[(int) $page->id()] = $this->node($page, $actor, $names);
        }
        $matches = array_values(array_filter($nodes, static fn (array $node): bool => $node['open']
            && ($state === null || $node['state'] === $state)
            && ($text === '' || mb_stripos((string) $node['title'], $text) !== false)
            && (!$mine || $node['mine'])));

        return $this->screens->render($request, '@page_admin/list.html.twig', [
            'tree' => $filtered ? [] : self::tree($all, $nodes),
            'matches' => $filtered ? array_map(fn (array $node): array => $node + ['above' => $this->above($node['page'], $nodes)], $matches) : [],
            'filtered' => $filtered,
            'filters' => ['state' => $state === null ? '' : $state->value, 'q' => $text, 'mine' => $mine],
            'states' => array_combine(
                array_map(static fn (PageState $s): string => $s->value, [PageState::Draft, PageState::InReview, PageState::Approved, PageState::Published]),
                array_map(PageAdmin::stateLabel(...), [PageState::Draft, PageState::InReview, PageState::Approved, PageState::Published]),
            ),
            'can_create' => $actor->has('page.create'),
            'total' => count(array_filter($nodes, static fn (array $node): bool => $node['open'])),
        ]);
    }

    /**
     * @param array<int, string> $names
     *
     * @return array{page: Page, id: int, title: string|Message, locale: string, url: ?string, state: PageState, state_label: string, author: ?string, updated: \DateTimeImmutable, mine: bool, open: bool, home: bool}
     */
    private function node(Page $page, Actor $actor, array $names): array
    {
        $translation = $this->translation($page);
        $author = $page->authorId();

        return [
            'page' => $page,
            'id' => (int) $page->id(),
            'title' => $translation?->title() ?? new Message('Page {id}', ['id' => (string) (int) $page->id()]),
            'locale' => $translation?->locale() ?? $this->site->defaultLocale,
            'url' => $translation === null ? null : $this->addresses->url($translation),
            'state' => $page->state(),
            'state_label' => PageAdmin::stateLabel($page->state()),
            'author' => $author === null ? null : ($names[$author] ?? null),
            'updated' => $page->updatedAt(),
            'mine' => $actor->is($author),
            'open' => $this->authorizer->can($actor, PageAction::View->value, $page),
            'home' => $this->addresses->isHome($page),
        ];
    }

    private function translation(Page $page): ?PageTranslation
    {
        $translations = $page->translations();

        return $translations[$this->site->defaultLocale] ?? ($translations === [] ? null : reset($translations));
    }

    /**
     * The pages that may be opened, nested under their parents. A page that may not be opened is only listed (as
     * "a page you can't open") when pages below it may be.
     *
     * @param list<Page>                       $pages top-down
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function tree(array $pages, array $nodes): array
    {
        $children = [];
        foreach ($pages as $page) {
            $children[(int) $page->parent()?->id()][] = (int) $page->id();
        }
        $build = static function (int $parent) use (&$build, $children, $nodes): array {
            $built = [];
            foreach ($children[$parent] ?? [] as $id) {
                $below = $build($id);
                if ($nodes[$id]['open'] === true || $below !== []) {
                    $built[] = $nodes[$id] + ['children' => $below];
                }
            }

            return $built;
        };

        /** @var list<array<string, mixed>> */
        return $build(0);
    }

    /**
     * Titles of the pages above a page, top first.
     *
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return list<string>
     */
    private function above(Page $page, array $nodes): array
    {
        $titles = [];
        for ($parent = $page->parent(); $parent !== null; $parent = $parent->parent()) {
            $node = $nodes[(int) $parent->id()] ?? null;
            $titles[] = $node !== null && $node['open'] === true && is_string($node['title']) ? $node['title'] : 'a page you can’t open';
        }

        return array_reverse($titles);
    }

    /**
     * @param list<Page> $pages
     *
     * @return array<int, string>
     */
    private function authorNames(array $pages): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (Page $page): ?int => $page->authorId(), $pages), static fn (?int $id): bool => $id !== null)));
        $names = [];
        foreach ($this->accounts->findAccounts($ids) as $account) {
            $names[(int) $account->id()] = $account->effectiveName();
        }

        return $names;
    }
}
