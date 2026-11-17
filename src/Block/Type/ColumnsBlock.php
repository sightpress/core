<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Type;

use SightPress\Core\Block\ParentBlockType;
use SightPress\Core\Block\SiteBlockContext;
use SightPress\Module\Block\BlockContext;

/**
 * Two to four columns side by side, each with its own blocks. On narrow screens and at high zoom they stack in
 * reading order, so nothing scrolls sideways (WCAG 1.4.10).
 */
final class ColumnsBlock implements ParentBlockType
{
    public const int MAX_BLOCKS_PER_COLUMN = 50;

    public function name(): string
    {
        return 'columns';
    }

    public function label(): string
    {
        return 'Columns';
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
            'required' => ['columns'],
            'properties' => [
                'columns' => [
                    'title' => 'Columns', 'description' => '2 to 4 columns side by side on wide screens, one after another on narrow ones.',
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 4,
                    'items' => [
                        'title' => 'Column',
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['blocks'],
                        'properties' => [
                            'blocks' => ['title' => 'Blocks', 'type' => 'array', 'maxItems' => self::MAX_BLOCKS_PER_COLUMN, 'items' => ['type' => 'object']],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function childLists(array $data): array
    {
        $lists = [];
        foreach (self::columns($data) as $index => $blocks) {
            $lists['/columns/' . $index . '/blocks'] = $blocks;
        }

        return $lists;
    }

    public function withChildLists(array $data, array $lists): array
    {
        $columns = [];
        foreach (array_keys(self::columns($data)) as $index) {
            $columns[] = ['blocks' => $lists['/columns/' . $index . '/blocks'] ?? []];
        }
        $data['columns'] = $columns;

        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        if (!$context instanceof SiteBlockContext) {
            return '';
        }
        $columns = self::columns($data);
        $html = '';
        foreach ($columns as $blocks) {
            /** @var list<array{type: string, version: int, data: array<string, mixed>}> $blocks */
            $html .= '<div class="block-column">' . $context->renderHtml($blocks) . '</div>';
        }

        return sprintf('<div class="block-columns block-columns--%d">%s</div>', count($columns), $html);
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        if (!$context instanceof SiteBlockContext) {
            return '';
        }
        $parts = [];
        foreach (self::columns($data) as $blocks) {
            /** @var list<array{type: string, version: int, data: array<string, mixed>}> $blocks */
            $parts[] = $context->renderMarkdown($blocks);
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<list<mixed>>
     */
    private static function columns(array $data): array
    {
        $columns = [];
        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
            $blocks = is_array($column) ? ($column['blocks'] ?? []) : [];
            $columns[] = is_array($blocks) ? array_values($blocks) : [];
        }

        return $columns;
    }
}
