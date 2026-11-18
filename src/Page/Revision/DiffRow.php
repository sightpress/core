<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Revision;

use SightPress\Core\Template\Message;

/**
 * One field that changed between two versions of a page in one language: where it is (for example "Block 2: Image ›
 * Alternative text") and its words, kept, removed or added.
 */
final readonly class DiffRow
{
    public const string CHANGED = 'changed';

    /**
     * @param list<Message>                            $label each part translated on its own, shown joined with ›
     * @param string                                   $status {@see TextDiff::ADDED}, {@see TextDiff::REMOVED} or
     *                                                         {@see CHANGED}
     * @param list<array{string, string|Message}>      $parts  `[kind, text]` in reading order; a {@see Message} is
     *                                                         words of the interface ("Yes"), anything else is the
     *                                                         page's own text
     */
    public function __construct(
        public array $label,
        public string $status,
        public array $parts,
    ) {
    }
}
