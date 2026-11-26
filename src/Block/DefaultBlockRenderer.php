<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Core\Block\Contract\BlockRenderer;
use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Core\Block\Schema\SchemaWalker;
use SightPress\Core\Content\BlockTypeCatalogue;
use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * Renders stored blocks with their types, after bringing old data up to the current version. Blocks of types that
 * aren't installed (or saved by a newer version) are left out rather than breaking the page. Schema.org nodes of
 * blocks inside other blocks (columns) are collected too. A block's looks become classes on a wrapper around its HTML
 * ({@see BlockStyleChoices}); Markdown and schema.org nodes don't change with them. With a
 * {@see SiteBlockContext}, every file the blocks use is looked up in one query first.
 */
final readonly class DefaultBlockRenderer implements BlockRenderer
{
    public function __construct(private BlockTypeCatalogue $types)
    {
    }

    public function html(array $blocks, BlockContext $context): string
    {
        $html = [];
        foreach ($this->prepare($blocks, $context) as [$type, $data, $styles]) {
            $html[] = BlockStyleChoices::wrap($type->render($data, $context), $styles);
        }

        return implode("\n", array_filter($html, static fn (string $part): bool => $part !== ''));
    }

    public function markdown(array $blocks, BlockContext $context): string
    {
        $parts = [];
        foreach ($this->prepare($blocks, $context) as [$type, $data]) {
            $parts[] = trim($type->renderMarkdown($data, $context));
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    public function structuredData(array $blocks, BlockContext $context): array
    {
        $nodes = [];
        foreach ($this->prepare($blocks, $context) as [$type, $data]) {
            $node = $type->structuredData($data, $context);
            if ($node !== null) {
                $nodes[] = $node;
            }
            if ($type instanceof ParentBlockType) {
                foreach ($type->childLists($data) as $children) {
                    /** @var list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $children */
                    $nodes = [...$nodes, ...$this->structuredData($children, $context)];
                }
            }
        }

        return $nodes;
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks
     *
     * @return list<array{BlockType, array<string, mixed>, array<string, string>}>
     */
    private function prepare(array $blocks, BlockContext $context): array
    {
        $prepared = [];
        foreach ($blocks as $block) {
            $type = $this->types->get($block['type']);
            $data = $type === null ? null : BlockUpgrader::upgrade($type, $block['version'], $block['data']);
            if ($type !== null && $data !== null) {
                $prepared[] = [$type, $data, BlockUpgrader::styles($this->types->stylesOf($type->name()), $block['styles'] ?? null)];
            }
        }
        if ($context instanceof SiteBlockContext) {
            $context->preload($this->mediaIds($prepared));
        }

        return $prepared;
    }

    /**
     * @param list<array{0: BlockType, 1: array<string, mixed>, 2?: array<string, string>}> $prepared
     *
     * @return list<int>
     */
    private function mediaIds(array $prepared): array
    {
        $ids = [];
        foreach ($prepared as [$type, $data]) {
            $ids = [...$ids, ...SchemaWalker::mediaIds($type->schema(), $data)];
            if ($type instanceof ParentBlockType) {
                foreach ($type->childLists($data) as $children) {
                    /** @var list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $children */
                    $ids = [...$ids, ...$this->mediaIds($this->typed($children))];
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks
     *
     * @return list<array{BlockType, array<string, mixed>}>
     */
    private function typed(array $blocks): array
    {
        $typed = [];
        foreach ($blocks as $block) {
            $type = $this->types->get($block['type']);
            if ($type !== null) {
                $typed[] = [$type, $block['data']];
            }
        }

        return $typed;
    }
}
