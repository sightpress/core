<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Revision;

use SightPress\Core\Template\Message;

/**
 * A part of the comparison of two versions: the details of the page (title, address, search fields …) or one block,
 * with whether it was added, removed, changed or stayed the same, and the fields that changed.
 */
final readonly class DiffSection
{
    public const string SAME = 'same';

    /**
     * @param string        $status {@see SAME}, {@see DiffRow::CHANGED}, {@see TextDiff::ADDED} or {@see TextDiff::REMOVED}
     * @param list<DiffRow> $rows   the fields that changed, in order
     */
    public function __construct(
        public Message $heading,
        public string $status,
        public array $rows,
    ) {
    }
}
