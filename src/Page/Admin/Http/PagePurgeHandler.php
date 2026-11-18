<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/pages/{id}/delete`: deleting a page in the trash for good. `GET` asks first (what goes with it and that
 * its address will say "gone"); `POST` deletes it, its revisions and the pages under it, then shows the trash.
 * Needs `page.delete` (the page voter's "purge").
 */
final readonly class PagePurgeHandler implements RequestHandlerInterface
{
    public const string TRASH = PageAdmin::PATH . '?state=trash';

    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageEditor $editor,
        private PageRepository $pages,
        private Authorizer $authorizer,
        private Site $site,
    ) {
    }

    public static function path(int $id): string
    {
        return PageAdmin::editPath($id) . '/delete';
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        $page = $this->admin->page($request);
        if (!$this->authorizer->can($actor, PageAction::View->value, $page)) {
            throw new HttpNotFoundException($request, 'There is no page with that number.');
        }
        if (!$this->authorizer->can($actor, PageAction::Purge->value, $page)) {
            throw new HttpForbiddenException($request, 'Only pages in the trash can be deleted for good, by editors and admins.');
        }
        $translations = $page->translations();
        $translation = $translations[$this->site->defaultLocale] ?? (array_values($translations)[0] ?? null);
        $title = $translation?->title() ?? new Message('Page {id}', ['id' => (string) (int) $page->id()]);

        if ($request->getMethod() !== 'POST') {
            return $this->screens->render($request, '@page_admin/delete.html.twig', [
                'title' => $title,
                'below' => count($this->pages->descendants($page)),
                'action' => self::path((int) $page->id()),
                'cancel' => PageAdmin::editPath((int) $page->id()),
            ]);
        }
        try {
            $this->editor->purge($page, $actor->userId);
        } catch (PageException $exception) {
            return $this->screens->redirect($request, PageAdmin::editPath((int) $page->id()), $exception->text());
        }

        return $this->screens->redirect($request, self::TRASH, new Message('Deleted “{title}” for good. Its address now tells visitors the page is gone.', ['title' => $title]));
    }
}
