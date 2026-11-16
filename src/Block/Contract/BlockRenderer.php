<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Module\Block\BlockContext;

/**
 * Turns stored blocks into HTML, Markdown and schema.org nodes. Blocks of types that are no longer installed are
 * left out.
 */
interface BlockRenderer
{
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    public function html(array $blocks, BlockContext $context): string;

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    public function markdown(array $blocks, BlockContext $context): string;

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     *
     * @return list<array<string, mixed>>
     */
    public function structuredData(array $blocks, BlockContext $context): array;
}
