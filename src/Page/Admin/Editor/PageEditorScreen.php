<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Template\LanguageName;
use SightPress\Core\Template\Message;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Admin\Contract\AdminScreens;
use SightPress\Core\Block\Contract\BlockContexts;
use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\BlockValidator;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Block\Contract\BlockSchema;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Core\Http\Form\Field;
use SightPress\Core\Http\Form\FieldType;
use SightPress\Core\Http\Form\Form;
use SightPress\Core\Page\Admin\Http\PageOperationsHandler;
use SightPress\Core\Page\Admin\Http\PagePurgeHandler;
use SightPress\Core\Page\Admin\Http\PageRevisionsHandler;
use SightPress\Core\Page\Admin\Http\PageScheduleHandler;
use SightPress\Core\Page\Admin\Http\PageTranslationHandler;
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PublishReport;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\PageRepository;
use SightPress\Core\Page\PageShift;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\Schedule\LocalTime;
use SightPress\Core\Page\Schedule\PageSchedule;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Rbac\Contract\PageState;
use SightPress\Core\Tenant\LocaleCode;
use SightPress\Core\Tenant\Site;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Renders the page editor (`/admin/pages/{id}`): what the page is about, its search & AI fields with a text-only
 * preview of the search result, its blocks, the publish checks of the saved page and the workflow buttons the
 * person may use; then copying the page, its place in the site (buttons that move it one step and a "Move to" list)
 * and, for a page in the trash, deleting it for good. People who may see the page but not change it now get the
 * same screen without the form.
 */
final readonly class PageEditorScreen
{
    /** Choices of the share image are `media-12`: a select's values can't be bare numbers. */
    public const string MEDIA_PREFIX = 'media-';

    public function __construct(
        private AdminScreens $screens,
        private PageAdmin $admin,
        private PageAddresses $addresses,
        private PageWorkflow $workflow,
        private PublishChecker $checks,
        private Authorizer $authorizer,
        private BlockFormView $view,
        private BlockTypeCatalogue $types,
        private BlockValidator $validator,
        private BlockRenderer $renderer,
        private BlockContexts $contexts,
        private MediaChoices $media,
        private Site $site,
        private PageRepository $pages,
    ) {
    }

    /**
     * The fields about the page, with ids that the publish checks link to (`en-gb-title`, `en-gb-description` …).
     * The share image is only offered when the media library has pictures.
     */
    public function form(string $locale, Page $page, ?int $socialImage = null): Form
    {
        $length = self::length(...);
        $parent = $page->parent()?->translation($locale);

        $images = self::prefixed($this->media->for(BlockSchema::FORMAT_IMAGE, $socialImage));

        $fields = [
            new Field('title', 'Title', hint: 'Shown at the top of the page, in the browser tab and in menus.', constraints: [$length(PageContent::TITLE_MAX_LENGTH)]),
            new Field('slug', 'Address', hint: new Message('The last part of the page’s web address, after {path}. Lower-case letters a to z, digits and hyphens. Leave it empty to make one from the title. Pages below this one move with it.', ['path' => $parent === null ? 'the site’s address' : '/' . $parent->path() . '/']), required: false),
            new Field('layout', 'Layout', FieldType::Select, choices: $this->admin->layouts($page->layout())),
            new Field('summary', 'Summary', FieldType::Textarea, hint: 'One or two sentences about the page. Shown in lists of pages and read by AI search engines.', required: false, constraints: [$length(PageContent::SUMMARY_MAX_LENGTH)]),
            new Field('seo_title', 'Title in search results', hint: 'Only when it should differ from the title. Leave it empty to use the title.', required: false, constraints: [$length(PageContent::SEO_TITLE_MAX_LENGTH)]),
            new Field('description', 'Description for search engines', FieldType::Textarea, hint: new Message('One or two sentences. Search engines show about {max} characters.', ['max' => (string) PublishChecker::DESCRIPTION_MAX]), required: false, constraints: [$length(PageContent::DESCRIPTION_MAX_LENGTH)]),
        ];
        if ($images !== []) {
            $fields[] = new Field('social_image', 'Picture shown when the page is shared', FieldType::Select, hint: 'Leave it empty to use the site’s share image.', required: false, choices: $images);
        }
        $fields = [
            ...$fields,
            new Field('noindex', 'Ask search engines and AI search engines not to list this page', FieldType::Checkbox, hint: 'It is then also left out of the sitemap and llms.txt.', required: false),
            new Field('canonical_url', 'Address of the original', FieldType::Url, hint: 'Only when this page is a copy of a page on another site: its full address, starting with https://.', required: false, constraints: [$length(PageContent::CANONICAL_MAX_LENGTH)]),
        ];

        return Form::create(strtolower($locale), ...$fields);
    }

    /**
     * The form filled with the page's saved content in the locale.
     */
    public function saved(string $locale, Page $page): Form
    {
        $translation = $page->translation($locale);
        $form = $this->form($locale, $page, $translation?->socialImage());

        return $form->withValues($translation === null ? ['layout' => $page->layout()] : [
            'title' => $translation->title(),
            'slug' => $translation->slug(),
            'layout' => $page->layout(),
            'summary' => $translation->summary() ?? '',
            'seo_title' => $translation->seoTitle() ?? '',
            'description' => $translation->description() ?? '',
            'social_image' => $translation->socialImage() === null ? '' : self::MEDIA_PREFIX . $translation->socialImage(),
            'noindex' => $translation->isNoindex() ? '1' : '',
            'canonical_url' => $translation->canonicalUrl() ?? '',
        ]);
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     * @param array<string, string|Message>                                            $blockErrors pointer => message
     */
    public function render(
        ServerRequestInterface $request,
        Page $page,
        string $locale,
        Form $form,
        array $blocks,
        array $blockErrors = [],
        ?string $focus = null,
        ?PublishReport $refused = null,
        int $status = 200,
    ): ResponseInterface {
        $actor = Actor::fromRequest($request);
        $editable = $this->authorizer->can($actor, PageAction::Edit->value, $page);
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();
        $editor = $this->view->build($locale, $blocks, $blockErrors, $editable ? $this->previews($locale, $blocks, $baseUrl, $request) : []);
        $translation = $page->translation($locale);
        $title = $translation?->title() ?? new Message('Page {id}', ['id' => (string) (int) $page->id()]);
        $errorCount = count($form->errors()) + count($editor->errors);

        return $this->screens->render($request, '@page_admin/editor.html.twig', [
            'page' => $page,
            'page_title' => $title,
            'locale' => $locale,
            'locales' => count($this->site->locales) > 1 ? $this->site->locales : [],
            'state' => PageAdmin::stateLabel($page->state()),
            'editable' => $editable,
            'form' => $form,
            'blocks' => $editor->list,
            'block_errors' => $editor->errors,
            'error_count' => $errorCount,
            'focus' => $errorCount > 0 ? null : $focus,
            'action' => PageAdmin::editPath((int) $page->id()) . ($locale === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($locale)),
            'workflow_action' => PageAdmin::workflowPath((int) $page->id()),
            'transitions' => array_map(static fn (PageTransition $transition): array => ['value' => $transition->value, 'label' => Message::of($transition->label()), 'backwards' => $transition->isBackwards(), 'danger' => $transition === PageTransition::Trash], $this->workflow->available($page, $actor)),
            'url' => $translation === null ? null : $this->addresses->url($translation),
            'search' => $this->searchPreview($form, $page, $locale, $baseUrl),
            'description_length' => mb_strlen($form->text('description')),
            'description_max' => PublishChecker::DESCRIPTION_MAX,
            'report' => $refused ?? ($translation === null ? null : $this->checks->check($page, $translation->isDraft() ? $locale : null)),
            'translations' => $this->translations($page, $locale),
            'translations_action' => PageTranslationHandler::path((int) $page->id()),
            'draft_translation' => $translation?->isDraft() === true,
            'current_has_translation' => $translation !== null,
            'is_main_locale' => $locale === $this->site->defaultLocale,
            'may_publish' => $actor->has('page.publish'),
            'report_focus' => $refused !== null,
            'operations_action' => PageOperationsHandler::path((int) $page->id()),
            'schedule' => $actor->has(PageSchedule::PERMISSION) && !in_array($page->state(), [PageState::Archived, PageState::Trash], true) ? $this->schedule($request, $page) : null,
            'may_copy' => $actor->has('page.create') && $page->state() !== PageState::Trash,
            'place' => PageOperationsHandler::mayMove($actor) && $page->state() !== PageState::Trash ? $this->place($page) : null,
            'revisions_url' => $translation === null ? null : PageRevisionsHandler::path((int) $page->id(), $locale, $this->site->defaultLocale),
            'delete_url' => $this->authorizer->can($actor, PageAction::Purge->value, $page) ? PagePurgeHandler::path((int) $page->id()) : null,
        ], $status);
    }

    /**
     * The "Schedule" section: what is scheduled, and the form's values in the person's time zone (or what they typed,
     * when the schedule handler sent them back with a problem).
     *
     * @return array{action: string, timezone: string, published: bool, approved: bool, publish_at: ?\DateTimeImmutable, unpublish_at: ?\DateTimeImmutable, publish_value: string, unpublish_value: string}
     */
    private function schedule(ServerRequestInterface $request, Page $page): array
    {
        $timezone = PageScheduleHandler::timezone($request, $this->site);
        $typed = $request->getQueryParams();

        return [
            'action' => PageScheduleHandler::path((int) $page->id()),
            'timezone' => $timezone,
            'published' => $page->state() === PageState::Published,
            'approved' => $page->state() === PageState::Approved,
            'publish_at' => $page->publishAt(),
            'unpublish_at' => $page->unpublishAt(),
            'publish_value' => is_string($typed['publish_at'] ?? null) ? mb_substr($typed['publish_at'], 0, 32) : LocalTime::field($page->publishAt(), $timezone),
            'unpublish_value' => is_string($typed['unpublish_at'] ?? null) ? mb_substr($typed['unpublish_at'], 0, 32) : LocalTime::field($page->unpublishAt(), $timezone),
        ];
    }

    /**
     * Where the page is in the site, for the "Place in the site" section: the pages above it, the one-step moves it
     * can make, and every page it can be moved under (not itself, the pages under it or pages in the trash).
     *
     * @return array{above: list<string>, shifts: list<array{value: string, label: string}>, parents: array<int|string, Message>}
     */
    private function place(Page $page): array
    {
        $parent = $page->parent();
        $count = count($this->pages->children($parent));
        $shifts = array_filter([
            PageShift::Up->value => $page->position() > 0,
            PageShift::Down->value => $page->position() < $count - 1,
            PageShift::In->value => $page->position() > 0,
            PageShift::Out->value => $parent !== null,
        ]);
        $parents = [PageOperationsHandler::TOP => new Message('The top level of the site')];
        $above = [];
        for ($up = $parent; $up !== null; $up = $up->parent()) {
            $above[] = $this->title($up);
        }
        foreach ($this->pages->all() as $candidate) {
            if ($candidate === $page || $page->isAncestorOf($candidate) || $candidate->state() === PageState::Trash || $candidate->depth() >= Page::MAX_DEPTH - 1) {
                continue;
            }
            $translation = $candidate->translation($this->site->defaultLocale) ?? (array_values($candidate->translations())[0] ?? null);
            $parents[(string) $candidate->id()] = new Message('Under “{title}” ({url})', ['title' => $this->title($candidate), 'url' => $translation === null ? '' : $this->addresses->url($translation)]);
        }

        return [
            'above' => array_reverse($above),
            'shifts' => array_values(array_map(static fn (string $shift): array => ['value' => $shift, 'label' => PageShift::from($shift)->label()], array_keys($shifts))),
            'parents' => $parents,
        ];
    }

    private function title(Page $page): string
    {
        $translation = $page->translation($this->site->defaultLocale) ?? (array_values($page->translations())[0] ?? null);

        return $translation?->title() ?? sprintf('Page %d', (int) $page->id());
    }

    /**
     * Each of the site's languages (the main one first) with how far the page is in it: not written yet, a draft
     * (of its own, or because the page isn't published) or published; and the languages it can be copied from.
     * Empty when the site has one language.
     *
     * @return list<array{code: string, name: LanguageName, status: string, current: bool, url: string, sources: array<string, LanguageName>}>
     */
    private function translations(Page $page, string $current): array
    {
        if (count($this->site->locales) < 2) {
            return [];
        }
        $locales = array_values(array_unique([$this->site->defaultLocale, ...$this->site->locales]));
        $sources = [];
        foreach ($locales as $locale) {
            if ($page->translation($locale) !== null) {
                $sources[$locale] = new LanguageName($locale);
            }
        }
        $rows = [];
        foreach ($locales as $locale) {
            $translation = $page->translation($locale);
            $rows[] = [
                'code' => $locale,
                'name' => new LanguageName($locale),
                'status' => match (true) {
                    $translation === null => 'Not written yet',
                    $translation->isPublished() => 'Published',
                    $translation->isDraft() => 'Draft translation',
                    default => 'Draft',
                },
                'current' => $locale === $current,
                'url' => PageAdmin::editPath((int) $page->id()) . ($locale === $this->site->defaultLocale ? '' : '?locale=' . rawurlencode($locale)),
                'sources' => $translation === null ? $sources : [],
            ];
        }

        return $rows;
    }

    /**
     * What a search result could look like, from the form as it is now: the title, the address and the
     * description (or the summary when there is none).
     *
     * @return array{title: string, url: string, description: ?string}
     */
    private function searchPreview(Form $form, Page $page, string $locale, string $baseUrl): array
    {
        $title = $form->text('seo_title') !== '' ? $form->text('seo_title') : $form->text('title');
        $translation = $page->translation($locale);
        $description = $form->text('description') !== '' ? $form->text('description') : $form->text('summary');

        return [
            'title' => $title === '' ? 'Untitled page' : $title . ' - ' . $this->site->name,
            'url' => $baseUrl . ($translation === null ? '/' : $this->addresses->url($translation)),
            'description' => $description === '' ? null : $description,
        ];
    }

    /**
     * The HTML of every block with rich text (also inside columns), cleaned as it would be saved, keyed by the
     * block's pointer. A block that can't be saved yet gets no preview.
     *
     * @param list<mixed> $blocks
     *
     * @return array<string, string>
     */
    private function previews(string $locale, array $blocks, string $baseUrl, ServerRequestInterface $request, string $pointer = '/blocks'): array
    {
        $previews = [];
        foreach ($blocks as $index => $block) {
            $at = $pointer . '/' . $index;
            $type = is_array($block) && is_string($block['type'] ?? null) ? $this->types->get($block['type']) : null;
            if ($type === null || !is_array($block)) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $previews += $this->inside($locale, $type->schema(), $data, $at . '/data', $baseUrl, $request);
            $hasHtml = array_any(BlockData::properties($type->schema()), static fn (array $property): bool => ControlKind::of($property) === ControlKind::Html);
            if ($hasHtml) {
                try {
                    $clean = $this->validator->validate([$block]);
                    $previews[$at] = $this->renderer->html($clean->blocks, $this->contexts->create($locale, $baseUrl, $request));
                } catch (InvalidBlocks) {
                    // Nothing to show until it can be saved.
                }
            }
        }

        return $previews;
    }

    /**
     * The previews of the blocks inside a block's data: in its block lists, also inside its items (columns).
     *
     * @param array<string, mixed>    $schema
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private function inside(string $locale, array $schema, array $data, string $pointer, string $baseUrl, ServerRequestInterface $request): array
    {
        $previews = [];
        foreach (BlockData::properties($schema) as $name => $property) {
            $value = $data[$name] ?? null;
            $kind = ControlKind::of($property);
            if (!is_array($value) || !in_array($kind, [ControlKind::Blocks, ControlKind::Items], true)) {
                continue;
            }
            if ($kind === ControlKind::Blocks) {
                $previews += $this->previews($locale, array_values($value), $baseUrl, $request, $pointer . '/' . $name);
                continue;
            }
            /** @var array<string, mixed> $items */
            $items = is_array($property['items'] ?? null) ? $property['items'] : [];
            foreach (array_values($value) as $index => $item) {
                $previews += $this->inside($locale, $items, is_array($item) ? $item : [], sprintf('%s/%s/%d', $pointer, $name, $index), $baseUrl, $request);
            }
        }

        return $previews;
    }

    /**
     * @param array<int, string|Message> $choices
     *
     * @return array<string, string|Message>
     */
    private static function prefixed(array $choices): array
    {
        $prefixed = [];
        foreach ($choices as $id => $label) {
            $prefixed[self::MEDIA_PREFIX . $id] = $label;
        }

        return $prefixed;
    }

    /**
     * The media id of a share image choice (`media-12`), or null.
     */
    public static function mediaId(mixed $value): ?int
    {
        return is_string($value) && preg_match('/^' . self::MEDIA_PREFIX . '([1-9]\\d*)$/', $value, $id) === 1 ? (int) $id[1] : null;
    }

    /**
     * @param positive-int $max
     */
    private static function length(int $max): Length
    {
        return new Length(max: $max, maxMessage: '{field} must be {{ limit }} characters or fewer');
    }
}
