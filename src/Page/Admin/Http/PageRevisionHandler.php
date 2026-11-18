<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\PageEditor;
use SightPress\Core\Page\PageException;
use SightPress\Core\Page\Revision\DiffRow;
use SightPress\Core\Page\Revision\DiffSection;
use SightPress\Core\Page\Revision\RevisionDiff;
use SightPress\Core\Page\Revision\RevisionHistory;
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
 * `/admin/pages/{id}/revisions/{revision}`: one version of a page in one language (`?locale=`), compared with the
 * version before it, field by field and block by block. Removed and added words are marked in words as well as with
 * `<del>` and `<ins>`, so the comparison reads the same without colour, without styles and with a screen reader.
 *
 * `POST` restores the version as a new revision ({@see PageEditor::restore()}), for people who may change the page;
 * it never publishes and never changes the page's state.
 */
final readonly class PageRevisionHandler implements RequestHandlerInterface
{
    public const string NUMBER_ATTRIBUTE = 'revision';

    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private RevisionHistory $history,
        private RevisionDiff $diff,
        private PageEditor $editor,
        private Authorizer $authorizer,
        private AccountDirectory $accounts,
        private Site $site,
    ) {
    }

    public static function path(int $id, int $number, string $locale, string $defaultLocale): string
    {
        return PageAdmin::editPath($id) . '/revisions/' . $number . ($locale === $defaultLocale ? '' : '?locale=' . rawurlencode($locale));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$page, $locale] = PageRevisionsHandler::open($request, $this->admin, $this->authorizer, $this->site);
        $id = (int) $page->id();
        $number = $request->getAttribute(self::NUMBER_ATTRIBUTE);
        $version = is_string($number) && ctype_digit($number) ? $this->history->version($page, $locale, (int) $number) : null;
        $kept = $version === null ? null : RevisionHistory::translation($version->revision, $locale);
        if ($version === null || $kept === null) {
            throw new HttpNotFoundException($request, 'The page has no such version in that language.');
        }
        $actor = Actor::fromRequest($request);
        $mayEdit = $this->authorizer->can($actor, PageAction::Edit->value, $page);
        $self = self::path($id, $version->revision->number(), $locale, $this->site->defaultLocale);

        if ($request->getMethod() === 'POST') {
            if (!$mayEdit) {
                throw new HttpForbiddenException($request, 'You can’t change this page now.');
            }
            try {
                $saved = $this->editor->restore($page, $version->revision, $locale, $actor->userId);
            } catch (PageException $exception) {
                return $this->screens->redirect($request, $self, $exception->text());
            } catch (InvalidBlocks) {
                return $this->screens->redirect($request, $self, new Message('Version {number} can’t be restored: some of its blocks no longer pass the checks. Copy what you need from it instead.', ['number' => $version->revision->number()]));
            }

            return $this->screens->redirect(
                $request,
                PageAdmin::editPath($id) . ($locale === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($locale)),
                new Message('Restored version {number} as version {new}. Nothing was published: the page is still {state}.', [
                    'number' => $version->revision->number(),
                    'new' => $saved->number(),
                    'state' => Message::of(PageAdmin::stateLabel($page->state()))->lowerFirst(),
                ]),
            );
        }

        $previous = $version->previous === null ? null : RevisionHistory::translation($version->previous, $locale);
        $sections = $this->diff->compare($previous, $kept);
        $savedBy = $version->revision->savedBy();
        $names = PageRevisionsHandler::names($this->accounts, [$savedBy]);
        $translation = $page->translation($locale);

        return $this->screens->render($request, '@page_admin/revision.html.twig', [
            'title' => PageRevisionsHandler::title($page, $locale, $this->site),
            'number' => $version->revision->number(),
            'previous' => $version->previous?->number(),
            'saved' => $version->revision->createdAt(),
            'by' => $savedBy === null ? null : ($names[$savedBy] ?? null),
            'by_note' => PageRevisionsHandler::byNote($savedBy, $names),
            'state' => $version->state === null ? null : PageAdmin::stateLabel($version->state),
            'language' => count($this->site->locales) > 1 ? new LanguageName($locale) : null,
            'unchanged' => RevisionDiff::isUnchanged($sections),
            'sections' => array_map(self::section(...), $sections),
            'list_url' => PageRevisionsHandler::path($id, $locale, $this->site->defaultLocale),
            'action' => $self,
            'may_restore' => $mayEdit && $translation?->isPublished() !== true,
            'restore_blocked' => $mayEdit && $translation?->isPublished() === true,
        ]);
    }

    /**
     * @return array{heading: Message, status: string, rows: list<array{label: list<Message>, status: string, parts: list<array{kind: string, text: ?string, words: ?Message}>}>}
     */
    private static function section(DiffSection $section): array
    {
        return [
            'heading' => $section->heading,
            'status' => $section->status,
            'rows' => array_map(static fn (DiffRow $row): array => [
                'label' => $row->label,
                'status' => $row->status,
                'parts' => array_map(static fn (array $part): array => [
                    'kind' => $part[0],
                    'text' => $part[1] instanceof Message ? null : $part[1],
                    'words' => $part[1] instanceof Message ? $part[1] : null,
                ], $row->parts),
            ], $section->rows),
        ];
    }
}
