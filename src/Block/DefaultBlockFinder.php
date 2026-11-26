<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Core\Block\Contract\BlockFinder;
use SightPress\Core\Content\BlockTypeCatalogue;

/**
 * Walks stored blocks in reading order, into blocks that hold blocks, skipping types that aren't installed.
 */
final readonly class DefaultBlockFinder implements BlockFinder
{
    public function __construct(private BlockTypeCatalogue $types)
    {
    }

    public function find(array $blocks, string $type): array
    {
        $found = [];
        foreach ($blocks as $block) {
            $blockType = $this->types->get($block['type']);
            $data = $blockType === null ? null : BlockUpgrader::upgrade($blockType, $block['version'], $block['data']);
            if ($blockType === null || $data === null) {
                continue;
            }
            if ($block['type'] === $type) {
                $found[] = $data;
            }
            if ($blockType instanceof ParentBlockType) {
                foreach ($blockType->childLists($data) as $children) {
                    /** @var list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $children */
                    $found = [...$found, ...$this->find($children, $type)];
                }
            }
        }

        return $found;
    }
}
