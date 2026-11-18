<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\Revision\RevisionHistory;
use SightPress\Core\Page\Revision\RevisionVersion;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use SightPress\Core\User\Contract\AccountDirectory;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/pages/{id}/revisions`: the versions of a page in one language (`?locale=`, the main language when left
 * out), newest first, each with who saved it, when (in the viewer's time zone) and the page's state then, and a link
 * to what changed ({@see PageRevisionHandler}). Open to the people who may see the page in the editor.
 */
final readonly class PageRevisionsHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private RevisionHistory $history,
        private Authorizer $authorizer,
        private AccountDirectory $accounts,
        private Site $site,
    ) {
    }

    public static function path(int $id, string $locale, string $defaultLocale): string
    {
        return PageAdmin::editPath($id) . '/revisions' . ($locale === $defaultLocale ? '' : '?locale=' . rawurlencode($locale));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$page, $locale] = self::open($request, $this->admin, $this->authorizer, $this->site);
        $id = (int) $page->id();
        $versions = $this->history->versions($page, $locale);

        return $this->screens->render($request, '@page_admin/revisions.html.twig', [
            'title' => self::title($page, $locale, $this->site),
            'language' => count($this->site->locales) > 1 ? new LanguageName($locale) : null,
            'languages' => count($this->site->locales) > 1 ? array_map(fn (string $code): array => [
                'name' => new LanguageName($code),
                'url' => self::path($id, $code, $this->site->defaultLocale),
                'current' => $code === $locale,
            ], $this->site->locales) : [],
            'versions' => $this->rows($versions, $id, $locale),
            'edit_url' => PageAdmin::editPath($id) . ($locale === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($locale)),
        ]);
    }

    /**
     * The page and language of the request, once the person may see them.
     *
     * @return array{Page, string}
     *
     * @throws HttpForbiddenException when the person has no page permission at all
     * @throws HttpNotFoundException  when there is no such page for them, or the site isn't written in the language
     */
    public static function open(ServerRequestInterface $request, PageAdmin $admin, Authorizer $authorizer, Site $site): array
    {
        $actor = Actor::fromRequest($request);
        if (!PageListHandler::mayUse($actor)) {
            throw new HttpForbiddenException($request);
        }
        $page = $admin->page($request);
        if (!$authorizer->can($actor, PageAction::View->value, $page)) {
            throw new HttpNotFoundException($request, 'There is no page with that number.');
        }
        $locale = $request->getQueryParams()['locale'] ?? $site->defaultLocale;
        if (!is_string($locale) || !in_array($locale, $site->locales, true)) {
            throw new HttpNotFoundException($request, 'This site isn’t written in that language.');
        }

        return [$page, $locale];
    }

    /**
     * The page's title in the language, else in the main language, else "Page 12".
     */
    public static function title(Page $page, string $locale, Site $site): string|Message
    {
        return $page->translation($locale)?->title()
            ?? $page->translation($site->defaultLocale)?->title()
            ?? new Message('Page {id}', ['id' => (string) (int) $page->id()]);
    }

    /**
     * @param list<RevisionVersion> $versions
     *
     * @return list<array{number: int, saved: \DateTimeImmutable, by: ?string, by_note: ?Message, state: ?string, first: bool, url: string}>
     */
    private function rows(array $versions, int $id, string $locale): array
    {
        $names = self::names($this->accounts, array_map(static fn (RevisionVersion $version): ?int => $version->revision->savedBy(), $versions));

        return array_map(fn (RevisionVersion $version): array => [
            'number' => $version->revision->number(),
            'saved' => $version->revision->createdAt(),
            'by' => $names[$version->revision->savedBy() ?? 0] ?? null,
            'by_note' => self::byNote($version->revision->savedBy(), $names),
            'state' => $version->state === null ? null : PageAdmin::stateLabel($version->state),
            'first' => $version->previous === null,
            'url' => PageRevisionHandler::path($id, $version->revision->number(), $locale, $this->site->defaultLocale),
        ], $versions);
    }

    /**
     * What to say for who saved a version when there is no name to show: the system (the command line, a scheduled
     * task) or an account that was deleted.
     *
     * @param array<int, string> $names
     */
    public static function byNote(?int $savedBy, array $names): ?Message
    {
        return match (true) {
            $savedBy === null => new Message('The system'),
            isset($names[$savedBy]) => null,
            default => new Message('An account that no longer exists'),
        };
    }

    /**
     * @param list<?int> $ids
     *
     * @return array<int, string> user id => name
     */
    public static function names(AccountDirectory $accounts, array $ids): array
    {
        $names = [];
        foreach ($accounts->findAccounts(array_values(array_unique(array_filter($ids, is_int(...))))) as $account) {
            $names[(int) $account->id()] = $account->effectiveName();
        }

        return $names;
    }
}
