<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Admin\Editor;

/**
 * The block part of the page editor, ready for the template: the top-level list (see {@see BlockFormView}) and
 * every error in it, in page order, for the error summary.
 */
final readonly class EditorBlocks
{
    /**
     * @param array<string, mixed>                     $list
     * @param list<array{id: string, message: string}> $errors
     */
    public function __construct(
        public array $list,
        public array $errors = [],
    ) {
    }
}
