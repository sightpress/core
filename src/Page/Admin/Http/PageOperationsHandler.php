<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\PageShift;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `POST /admin/pages/{id}/operations`: the editor's "Copy" and "Place in the site" buttons.
 *
 * - `op=copy`: copies the page into a new draft right after it ("Copy of …", every translation) and opens the copy.
 *   Needs `page.create`.
 * - `op=up|down|in|out`: moves the page one step ({@see PageShift}); `op=move`, `parent` (a page id, or `top`):
 *   moves it to the end of that page's subpages. Pages under it move with it, and old addresses lead to the new ones.
 *   Needs `page.edit.any`, because the addresses of other people's pages can change with it.
 *
 * Every operation comes back to an editor with what happened (or why it couldn't be done) in the status message.
 */
final readonly class PageOperationsHandler implements RequestHandlerInterface
{
    public const string TOP = 'top';

    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageEditor $editor,
        private PageRepository $pages,
        private PageAddresses $addresses,
        private Authorizer $authorizer,
        private Site $site,
    ) {
    }

    public static function path(int $id): string
    {
        return PageAdmin::editPath($id) . '/operations';
    }

    /**
     * Whether the person may move pages around the tree.
     */
    public static function mayMove(Actor $actor): bool
    {
        return $actor->has('page.edit.any');
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!PageListHandler::mayUse($actor)) {
            throw new HttpForbiddenException($request);
        }
        $page = $this->admin->page($request);
        if (!$this->authorizer->can($actor, PageAction::View->value, $page)) {
            throw new HttpNotFoundException($request, 'There is no page with that number.');
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $op = is_string($body['op'] ?? null) ? $body['op'] : '';
        $edit = PageAdmin::editPath((int) $page->id());

        if ($op === 'copy') {
            if (!$actor->has('page.create')) {
                throw new HttpForbiddenException($request, 'You can’t start new pages.');
            }
            try {
                $copy = $this->editor->copy($page, $actor->userId);
            } catch (PageException $exception) {
                return $this->screens->redirect($request, $edit, $exception->text());
            }

            return $this->screens->redirect($request, PageAdmin::editPath((int) $copy->id()), 'Copied the page. This is the copy, a new draft: change its title and address before you publish it.');
        }

        $shift = PageShift::tryFrom($op);
        if ($shift === null && $op !== 'move') {
            throw new HttpBadRequestException($request, 'Choose one of the buttons.');
        }
        if (!self::mayMove($actor)) {
            throw new HttpForbiddenException($request, 'Only editors and admins can move pages.');
        }
        try {
            if ($shift !== null) {
                $this->editor->shift($page, $shift, $actor->userId);
            } else {
                $this->editor->move($page, $this->parent($request, $body), PHP_INT_MAX, $actor->userId);
            }
        } catch (PageException $exception) {
            return $this->screens->redirect($request, $edit, $exception->text());
        }
        $translation = $page->translation($this->site->defaultLocale) ?? (array_values($page->translations())[0] ?? null);

        return $this->screens->redirect($request, $edit, $translation === null
            ? 'Moved the page.'
            : new Message('Moved the page. Its address is now {url}; links to its old address still lead to it.', ['url' => $this->addresses->url($translation)]));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function parent(ServerRequestInterface $request, array $body): ?Page
    {
        $parent = $body['parent'] ?? null;
        if ($parent === self::TOP) {
            return null;
        }
        $found = is_string($parent) && ctype_digit($parent) ? $this->pages->find((int) $parent) : null;

        return $found ?? throw new HttpBadRequestException($request, 'Choose where to move the page.');
    }
}
