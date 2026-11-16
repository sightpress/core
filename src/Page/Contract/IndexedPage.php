<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

use DateTimeImmutable;

/**
 * One language of a published page, with what search engines and AI readers need to know about it.
 */
final readonly class IndexedPage
{
    /**
     * @param string                                                              $url        address on the site:
     *                                                                                        `/about`, `/pt-pt/sobre`,
     *                                                                                        `/` for the home page
     * @param bool                                                                $public     whether everyone may read
     *                                                                                        it (else members only)
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    public function __construct(
        public int $id,
        public string $locale,
        public string $url,
        public string $title,
        public ?string $seoTitle,
        public ?string $summary,
        public ?string $description,
        public bool $public,
        public bool $noindex,
        public ?string $canonicalUrl,
        public int $depth,
        public DateTimeImmutable $updatedAt,
        public array $blocks,
    ) {
    }

    /**
     * Whether search engines may list it: public, not `noindex`, and not a copy of another page.
     */
    public function isIndexable(): bool
    {
        return $this->public && !$this->noindex && $this->canonicalUrl === null;
    }
}
