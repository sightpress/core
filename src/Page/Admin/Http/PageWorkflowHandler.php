<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Page\Workflow\PublishBlocked;
use SightPress\Core\Page\Workflow\WorkflowException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;

/**
 * `POST /admin/pages/{id}/workflow`: the workflow buttons of the editor (submit for review, send back, approve,
 * publish, unpublish, archive, restore, put in or take out of the trash), with an optional note for the next person. They act on the saved page. When the publish
 * checks stop publishing, the editor comes back with the list of problems, which gets focus.
 */
final readonly class PageWorkflowHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageEditorScreen $screen,
        private PageWorkflow $workflow,
        private Site $site,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = Actor::fromRequest($request);
        if (!PageListHandler::mayUse($actor)) {
            throw new HttpForbiddenException($request);
        }
        $page = $this->admin->page($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $transition = PageTransition::tryFrom(is_string($body['transition'] ?? null) ? $body['transition'] : '')
            ?? throw new HttpBadRequestException($request, 'Choose one of the workflow buttons.');
        $comment = is_string($body['comment'] ?? null) ? $body['comment'] : null;
        $edit = PageAdmin::editPath((int) $page->id());

        try {
            $this->workflow->apply($page, $transition, $actor, $comment);
        } catch (PublishBlocked $blocked) {
            $locale = $this->site->defaultLocale;

            return $this->screen->render($request, $page, $locale, $this->screen->saved($locale, $page), $page->translation($locale)?->blocks() ?? [], refused: $blocked->report, status: 422);
        } catch (WorkflowException $exception) {
            if ($exception->forbidden) {
                throw new HttpForbiddenException($request, (string) $exception->text());
            }

            return $this->screens->redirect($request, $edit, $exception->text());
        }

        return $this->screens->redirect($request, $edit, match ($transition) {
            PageTransition::Submit => 'Sent for review. The editors have been told by email.',
            PageTransition::Reject => 'Sent back to draft. The writer can change it and send it again.',
            PageTransition::Approve => 'Approved. It can be published now.',
            PageTransition::Publish => 'Published. Visitors can see the page now.',
            PageTransition::Unpublish => 'Unpublished. Visitors can’t see the page any more; it is a draft again.',
            PageTransition::Archive => 'Archived. Visitors can’t see the page any more; it is kept with the archived pages.',
            PageTransition::Restore => 'Restored as a draft. Publish it again when it is ready.',
            PageTransition::Trash => 'Put in the trash. Its address now tells visitors the page is gone.',
            PageTransition::Untrash => 'Taken out of the trash as a draft. Publish it again when it is ready.',
        });
    }
}
