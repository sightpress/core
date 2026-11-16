<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

/**
 * Finds the blocks of one type in stored blocks, also inside other blocks (columns), for example the contact forms
 * of a page.
 */
interface BlockFinder
{
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     *
     * @return list<array<string, mixed>> the data of each block of that type, in reading order, at the current version
     */
    public function find(array $blocks, string $type): array;
}
