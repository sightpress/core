<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

use SightPress\Core\Template\Message;

/**
 * The blocks after a page editor button changed them, the sentence that says what happened (for the live region)
 * and the `id` that gets focus.
 */
final readonly class OperationResult
{
    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    public function __construct(
        public array $blocks,
        public string|Message $message,
        public string $focus,
    ) {
    }
}
