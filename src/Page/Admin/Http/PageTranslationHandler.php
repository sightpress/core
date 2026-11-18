<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `POST /admin/pages/{id}/translations`: the buttons of the editor's "Translations" section.
 *
 * - `op=translate`, `locale`, `from`: starts the translation into `locale` as a copy of the page in `from` (a draft
 *   of its own; the page in `from` doesn't change), then opens it in the editor. Needs `page.edit` on the page.
 * - `op=publish` / `op=draft`, `locale`: publishes the translation on its own (once its own publish checks find no
 *   errors; otherwise the editor comes back with the problems) or makes it a draft again. Needs `page.publish`.
 *
 * Only the site's languages are accepted.
 */
final readonly class PageTranslationHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageEditorScreen $screen,
        private PageEditor $editor,
        private PublishChecker $checks,
        private Authorizer $authorizer,
        private Site $site,
    ) {
    }

    public static function path(int $id): string
    {
        return PageAdmin::editPath($id) . '/translations';
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
        $locale = is_string($body['locale'] ?? null) ? $body['locale'] : '';
        if (!in_array($locale, $this->site->locales, true)) {
            throw new HttpBadRequestException($request, 'This site isn’t written in that language.');
        }
        $edit = PageAdmin::editPath((int) $page->id()) . ($locale === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($locale));
        $name = LocaleCode::describe($locale);

        $op = $body['op'] ?? null;
        if ($op === 'translate') {
            if (!$this->authorizer->can($actor, PageAction::Edit->value, $page)) {
                throw new HttpForbiddenException($request, 'You can’t change this page now.');
            }
            $from = is_string($body['from'] ?? null) ? $body['from'] : '';
            if (!in_array($from, $this->site->locales, true)) {
                throw new HttpBadRequestException($request, 'Choose a language the page is written in.');
            }
            try {
                $this->editor->translate($page, $locale, $from, $actor->userId);
            } catch (PageException $exception) {
                return $this->screens->redirect($request, PageAdmin::editPath((int) $page->id()), $exception->getMessage());
            }

            return $this->screens->redirect($request, $edit, sprintf(
                'Started the %s translation as a copy of the %s page. Translate the text, then publish the translation.',
                $name,
                LocaleCode::describe($from),
            ));
        }
        if ($op !== 'publish' && $op !== 'draft') {
            throw new HttpBadRequestException($request, 'Choose one of the translation buttons.');
        }
        if (!$actor->has('page.publish')) {
            throw new HttpForbiddenException($request, 'Only editors and admins can publish translations.');
        }
        $translation = $page->translation($locale) ?? throw new HttpBadRequestException($request, 'The page isn’t written in that language yet.');
        if ($op === 'publish') {
            $report = $this->checks->check($page, $locale);
            if (!$report->canPublish()) {
                return $this->screen->render($request, $page, $locale, $this->screen->saved($locale, $page), $translation->blocks(), refused: $report, status: 422);
            }
        }
        $changed = $this->editor->publishTranslation($page, $locale, $op === 'publish', $actor->userId);

        return $this->screens->redirect($request, $edit, match (true) {
            !$changed => 'Nothing was changed.',
            $op === 'draft' => sprintf('The %s translation is a draft again. Visitors can’t see it.', $name),
            $translation->isPublished() => sprintf('Published the %s translation. Visitors can see it now.', $name),
            default => sprintf('The %s translation is ready. Visitors can see it once the page is published.', $name),
        });
    }
}
