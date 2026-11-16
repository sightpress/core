<?php

declare(strict_types=1);

namespace SightPress\Core\Page\Contract;

/**
 * One language of a page that everyone may read: published and public.
 */
final readonly class PublicPage
{
    /**
     * @param string                                                              $url    path of the page on the site,
     *                                                                                    with the locale prefix, for
     *                                                                                    example `/pt-br/sobre`
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     */
    public function __construct(
        public int $id,
        public string $locale,
        public string $url,
        public string $title,
        public array $blocks,
    ) {
    }
}
