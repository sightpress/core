<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use SightPress\Core\Template\Message;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Http\Form\FormValidator;
use SightPress\Core\Http\Session\Flash;
use SightPress\Core\Http\Session\Session;
use SightPress\Core\Page\Admin\Editor\BlockData;
use SightPress\Core\Page\Admin\Editor\BlockOperation;
use SightPress\Core\Page\Admin\Editor\PageEditorScreen;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\Site;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * `/admin/pages/{id}`: the page editor. "Save page" writes a revision; the buttons that add, move or remove blocks
 * (`op`) change the form without saving and say what changed in the live region, with focus on the block, or on
 * the same button where it moved. "Update the preview" (`op=preview`) only shows the form again with the search
 * result preview and the rich text previews up to date. `?locale=` edits another of the site's languages.
 */
final readonly class PageEditorHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageEditorScreen $screen,
        private FormValidator $validator,
        private PageEditor $editor,
        private BlockData $data,
        private BlockOperation $operations,
        private Authorizer $authorizer,
        private ClockInterface $clock,
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
        if (!$this->authorizer->can($actor, PageAction::View->value, $page)) {
            throw new HttpNotFoundException($request, 'There is no page with that number.');
        }
        $locale = $request->getQueryParams()['locale'] ?? $this->site->defaultLocale;
        if (!is_string($locale) || !in_array($locale, $this->site->locales, true)) {
            throw new HttpNotFoundException($request, 'This site isn’t written in that language.');
        }
        if ($request->getMethod() !== 'POST') {
            return $this->screen->render($request, $page, $locale, $this->screen->saved($locale, $page), $page->translation($locale)?->blocks() ?? []);
        }
        if (!$this->authorizer->can($actor, PageAction::Edit->value, $page)) {
            throw new HttpForbiddenException($request, 'You can’t change this page now.');
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $blocks = $this->data->read($body['blocks'] ?? []);
        $op = is_string($body['op'] ?? null) ? $body['op'] : 'save';
        if ($op !== 'save') {
            return $this->change($request, $page, $locale, $body, $blocks, $op);
        }

        $form = $this->validator->submit($this->screen->form($locale, $page, PageEditorScreen::mediaId($body['social_image'] ?? null)), $body);
        if (!$form->isValid()) {
            return $this->screen->render($request, $page, $locale, $form, $blocks, status: 422);
        }
        try {
            $content = new PageContent(
                $form->text('title'),
                $form->text('slug'),
                $form->text('summary'),
                $form->text('description'),
                $blocks,
                $form->has('social_image') ? PageEditorScreen::mediaId($form->text('social_image')) : null,
                $form->text('seo_title'),
                $form->text('noindex') === '1',
                $form->text('canonical_url'),
            );
            if ($form->text('layout') !== $page->layout()) {
                $page->setLayout($form->text('layout'), $this->clock->now());
            }
            $public = $form->text('visibility') === PageEditorScreen::EVERYONE;
            if ($public !== $page->isPublic()) {
                $page->setPublic($public, $this->clock->now());
            }
            $this->editor->save($page, $locale, $content, $actor->userId);
        } catch (InvalidBlocks $exception) {
            $errors = [];
            foreach ($exception->violations as $violation) {
                $errors['/blocks' . $violation->pointer] ??= $violation->message;
            }

            return $this->screen->render($request, $page, $locale, $form, $blocks, $errors, status: 422);
        } catch (PageException $exception) {
            // The form has checked the other fields already: what is left is about the address.
            return $this->screen->render($request, $page, $locale, $form->withErrors(['slug' => $exception->text()]), $blocks, status: 422);
        }

        return $this->screens->redirect($request, PageAdmin::editPath((int) $page->id()) . ($locale === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($locale)), new Message(
            $page->state() === PageState::Published ? 'Saved “{title}”. The changes are on the site now.' : 'Saved “{title}”.',
            ['title' => $content->title],
        ));
    }

    /**
     * @param array<array-key, mixed>                                             $body
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    private function change(ServerRequestInterface $request, Page $page, string $locale, array $body, array $blocks, string $op): ResponseInterface
    {
        $form = $this->screen->form($locale, $page, PageEditorScreen::mediaId($body['social_image'] ?? null));
        $form = $form->withValues(self::values($form, $body));
        if ($op === 'preview') {
            Flash::add(Session::fromRequest($request), 'The previews show your changes. Save the page to keep them.');

            return $this->screen->render($request, $page, $locale, $form, $blocks, focus: 'search-preview');
        }
        $types = $body['add_type'] ?? [];
        [, $pointer] = array_pad(explode(':', $op, 2), 2, '');
        $addType = is_array($types) && is_string($types[$pointer] ?? null) ? $types[$pointer] : null;
        $result = $this->operations->apply($locale, $blocks, $op, $addType);
        if ($result === null) {
            Flash::add(Session::fromRequest($request), 'That button no longer matches the page. Nothing was changed; try again.');

            return $this->screen->render($request, $page, $locale, $form, $blocks);
        }
        Flash::add(Session::fromRequest($request), $result->message);

        return $this->screen->render($request, $page, $locale, $form, $result->blocks, focus: $result->focus);
    }

    /**
     * The form's fields as sent, for showing them again without checking them.
     *
     * @param array<array-key, mixed> $body
     *
     * @return array<string, string>
     */
    private static function values(Form $form, array $body): array
    {
        $values = [];
        foreach ($form->fields() as $field) {
            $value = $body[$field->name] ?? '';
            $values[$field->name] = is_string($value) ? $value : '';
        }

        return $values;
    }
}
