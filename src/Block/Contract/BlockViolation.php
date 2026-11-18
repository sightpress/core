<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Core\Template\Message;

/**
 * Something wrong with a block that stops it being saved: unknown type, data that doesn't fit the type's schema, a
 * file that isn't in the media library.
 */
final readonly class BlockViolation
{
    /**
     * @param string $pointer JSON Pointer into the page's block list, for example `/2/data/alt` or, inside columns,
     *                        `/0/data/columns/1/blocks/0/data/text`; the editor moves focus there
     * @param string|Message $message plain en-GB sentence that says how to fix it (shown translated)
     */
    public function __construct(
        public string $pointer,
        public string|Message $message,
    ) {
    }
}
