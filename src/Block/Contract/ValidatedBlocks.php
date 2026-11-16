<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Module\Block\A11ySeverity;

/**
 * Blocks that passed validation: upgraded to the current version of their type, rich text sanitised, ready to
 * store; plus the accessibility issues the block types' rules found (they don't stop a save, errors stop
 * publishing).
 */
final readonly class ValidatedBlocks
{
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     * @param list<BlockIssue>                                                    $issues
     * @param list<int>                                                           $mediaIds files the blocks use
     */
    public function __construct(
        public array $blocks,
        public array $issues = [],
        public array $mediaIds = [],
    ) {
    }

    /**
     * @return list<BlockIssue>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (BlockIssue $issue): bool => $issue->issue->severity === A11ySeverity::Error));
    }

    /**
     * @return list<BlockIssue>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (BlockIssue $issue): bool => $issue->issue->severity === A11ySeverity::Warning));
    }
}
