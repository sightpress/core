<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Module\Block\A11yIssue;

/**
 * An accessibility issue a block type's rule found in one block of a page.
 */
final readonly class BlockIssue
{
    /**
     * @param string $pointer JSON Pointer to the block in the page's block list, for example `/2` or
     *                        `/0/data/columns/1/blocks/0`; add {@see A11yIssue::$field} for the field
     */
    public function __construct(
        public string $pointer,
        public string $type,
        public A11yIssue $issue,
    ) {
    }

    /**
     * Pointer to the field the issue is about, or to the block when it is about the whole block.
     */
    public function fieldPointer(): string
    {
        return $this->issue->field === null ? $this->pointer : $this->pointer . '/data' . $this->issue->field;
    }
}
