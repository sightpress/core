<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Module\Block\BlockType;

/**
 * A core block type that holds other blocks (columns). Core validates and renders the blocks inside like the
 * page's own; a block that holds blocks can't sit inside another one.
 */
interface ParentBlockType extends BlockType
{
    /**
     * The block lists inside the data, keyed by their JSON Pointer in the data, for example `/columns/0/blocks`.
     *
     * @param array<string, mixed> $data data that passed the schema
     *
     * @return array<string, list<mixed>>
     */
    public function childLists(array $data): array;

    /**
     * The data with its block lists replaced (after validation changed them).
     *
     * @param array<string, mixed>                                                               $data
     * @param array<string, list<array{type: string, version: int, data: array<string, mixed>}>> $lists keyed like {@see childLists()}
     *
     * @return array<string, mixed>
     */
    public function withChildLists(array $data, array $lists): array;
}
