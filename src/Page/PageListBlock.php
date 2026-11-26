<?php

declare(strict_types=1);

namespace SightPress\Core\Page;

use SightPress\Core\Block\Contract\HttpBlockContext;
use SightPress\Core\Block\Contract\BlockSchema;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockVariant;
use SightPress\Module\Block\StyledBlockType;

/**
 * A list of pages, kept up to date: the pages under a page (or the top-level pages), or the pages changed most
 * recently. It is a real list (`<ul>`), each page one link with its summary, as a plain list or as cards. Only pages
 * everyone may read are listed ({@see PageListQuery}), so members-only pages never reach guests, search engines or
 * AI readers.
 *
 * Unlike other block types it reads the site's pages when it is drawn, through the page module's own query; it lives
 * in the page module for that reason.
 */
final readonly class PageListBlock implements StyledBlockType
{
    public const string CHILDREN = 'pages_under_a_page';

    public const string LATEST = 'latest_changed_pages';

    public const int DEFAULT_COUNT = 6;

    public function __construct(private PageListQuery $pages)
    {
    }

    public function name(): string
    {
        return 'page_list';
    }

    public function label(): string
    {
        return 'List of pages';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['source'],
            'properties' => [
                'source' => ['title' => 'Which pages', 'enum' => [self::CHILDREN, self::LATEST]],
                'parent' => ['title' => 'Under the page at', 'description' => 'The address of a page of this site, such as /services. Leave it empty for the top-level pages. Only used for the pages under a page.', 'type' => 'string', 'maxLength' => 2000, 'format' => BlockSchema::FORMAT_LINK],
                'count' => ['title' => 'How many pages, at most', 'type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                'show_summaries' => ['title' => 'Show each page\'s summary', 'type' => 'boolean'],
            ],
        ];
    }

    public function styles(): BlockStyles
    {
        return new BlockStyles([new BlockVariant('list', 'List'), new BlockVariant('cards', 'Cards')], [BlockOption::Background, BlockOption::Width, BlockOption::Spacing]);
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        $summaries = ($data['show_summaries'] ?? false) === true;
        $items = '';
        foreach ($this->items($data, $context) as $page) {
            $summary = $summaries && $page['summary'] !== null && $page['summary'] !== ''
                ? '<p class="block-page-list__summary">' . self::e($page['summary']) . '</p>'
                : '';
            $items .= '<li class="block-page-list__item"><a class="block-page-list__link" href="' . self::e($page['url']) . '">' . self::e($page['title']) . '</a>' . $summary . '</li>';
        }

        return $items === '' ? '' : '<ul class="block-page-list">' . $items . '</ul>';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        $summaries = ($data['show_summaries'] ?? false) === true;
        $lines = [];
        foreach ($this->items($data, $context) as $page) {
            $line = '- [' . self::markdown($page['title']) . '](' . str_replace([' ', ')'], ['%20', '%29'], $context->absoluteUrl(self::path($page['url'], $context))) . ')';
            $lines[] = $summaries && $page['summary'] !== null && $page['summary'] !== '' ? $line . ': ' . self::markdown($page['summary']) : $line;
        }

        return implode("\n", $lines);
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        $elements = [];
        foreach ($this->items($data, $context) as $index => $page) {
            $elements[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $page['title'], 'url' => $context->absoluteUrl(self::path($page['url'], $context))];
        }

        return $elements === [] ? null : ['@type' => 'ItemList', 'itemListElement' => $elements];
    }

    public function a11yRules(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{title: string, url: string, summary: ?string}>
     */
    private function items(array $data, BlockContext $context): array
    {
        $count = is_int($data['count'] ?? null) ? max(1, min(12, $data['count'])) : self::DEFAULT_COUNT;
        // The page the list is on; the Markdown version and the search index are made without a request, so there
        // the page can be in its own list.
        $current = $context instanceof HttpBlockContext ? $context->request()?->getUri()->getPath() : null;
        if (($data['source'] ?? null) === self::LATEST) {
            return $this->pages->latest($context->locale(), $count, $current);
        }
        $parent = is_string($data['parent'] ?? null) && trim($data['parent']) !== '' ? trim($data['parent']) : null;

        return $this->pages->children($parent, $context->locale(), $count, $current) ?? [];
    }

    /**
     * The address of a page relative to the locale's prefix, which {@see BlockContext::absoluteUrl()} adds again.
     */
    private static function path(string $url, BlockContext $context): string
    {
        $prefix = substr((string) parse_url($context->absoluteUrl('/x'), PHP_URL_PATH), 0, -2);

        return $prefix !== '' && str_starts_with($url, $prefix) ? substr($url, strlen($prefix)) : $url;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function markdown(string $text): string
    {
        return addcslashes(str_replace(["\r", "\n"], ' ', $text), '\\[]*_`#<>');
    }
}
