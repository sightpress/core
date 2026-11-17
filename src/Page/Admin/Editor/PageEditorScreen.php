<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

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
use SightPress\Core\Page\Admin\PageAdmin;
use SightPress\Core\Page\Contract\PageTransition;
use SightPress\Core\Page\Contract\PublishReport;
use SightPress\Core\Page\Entity\Page;
use SightPress\Core\Page\PageAddresses;
use SightPress\Core\Page\PageContent;
use SightPress\Core\Page\Publish\PublishChecker;
use SightPress\Core\Page\Workflow\PageWorkflow;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Rbac\Contract\Authorizer;
use SightPress\Core\Rbac\Contract\PageAction;
use SightPress\Core\Tenant\Site;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Renders the page editor (`/admin/pages/{id}`): what the page is about, its search & AI fields with a text-only
 * preview of the search result, its blocks, the publish checks of the saved page and the workflow buttons the
 * person may use. People who may see the page but not change it now get the same screen without the form.
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
            new Field('title', 'Title', hint: 'Shown at the top of the page, in the browser tab and in menus.', constraints: [$length(PageContent::TITLE_MAX_LENGTH, 'The title')]),
            new Field('slug', 'Address', hint: sprintf(
                'The last part of the page’s web address, after %s. Lower-case letters a to z, digits and hyphens. Leave it empty to make one from the title. Pages below this one move with it.',
                $parent === null ? 'the site’s address' : '/' . $parent->path() . '/',
            ), required: false),
            new Field('layout', 'Layout', FieldType::Select, choices: $this->admin->layouts($page->layout())),
            new Field('summary', 'Summary', FieldType::Textarea, hint: 'One or two sentences about the page. Shown in lists of pages and read by AI search engines.', required: false, constraints: [$length(PageContent::SUMMARY_MAX_LENGTH, 'The summary')]),
            new Field('seo_title', 'Title in search results', hint: 'Only when it should differ from the title. Leave it empty to use the title.', required: false, constraints: [$length(PageContent::SEO_TITLE_MAX_LENGTH, 'The title in search results')]),
            new Field('description', 'Description for search engines', FieldType::Textarea, hint: sprintf('One or two sentences. Search engines show about %d characters.', PublishChecker::DESCRIPTION_MAX), required: false, constraints: [$length(PageContent::DESCRIPTION_MAX_LENGTH, 'The description')]),
        ];
        if ($images !== []) {
            $fields[] = new Field('social_image', 'Picture shown when the page is shared', FieldType::Select, hint: 'Leave it empty to use the site’s share image.', required: false, choices: $images);
        }
        $fields = [
            ...$fields,
            new Field('noindex', 'Ask search engines and AI search engines not to list this page', FieldType::Checkbox, hint: 'It is then also left out of the sitemap and llms.txt.', required: false),
            new Field('canonical_url', 'Address of the original', FieldType::Url, hint: 'Only when this page is a copy of a page on another site: its full address, starting with https://.', required: false, constraints: [$length(PageContent::CANONICAL_MAX_LENGTH, 'The address of the original')]),
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
     * @param array<string, string>                                               $blockErrors pointer => message
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
        $title = $translation?->title() ?? sprintf('Page %d', (int) $page->id());
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
            'transitions' => array_map(static fn (PageTransition $transition): array => ['value' => $transition->value, 'label' => $transition->label()], $this->workflow->available($page, $actor)),
            'url' => $translation === null ? null : $this->addresses->url($translation),
            'search' => $this->searchPreview($form, $page, $locale, $baseUrl),
            'description_length' => mb_strlen($form->text('description')),
            'description_max' => PublishChecker::DESCRIPTION_MAX,
            'report' => $refused ?? ($translation === null ? null : $this->checks->check($page)),
            'report_focus' => $refused !== null,
        ], $status);
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
     * @param array<int, string> $choices
     *
     * @return array<string, string>
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
    private static function length(int $max, string $what): Length
    {
        return new Length(max: $max, maxMessage: sprintf('%s must be %d characters or fewer', $what, $max));
    }
}
