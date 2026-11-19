<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Analytics;

use SightPress\Core\Page\Contract\PageLink;
use SightPress\Core\Page\Contract\PageLinks;

/**
 * Pages as links, from a list the test gives (id + locale => title and address).
 */
final class FakePageLinks implements PageLinks
{
    /** @var array<string, array<int, PageLink>> locale => id => link */
    private array $links = [];

    public function add(int $id, string $locale, string $title, string $url): void
    {
        $this->links[$locale][$id] = new PageLink($id, $title, $url, 0, true, true, true);
    }

    public function find(array $ids, string $locale): array
    {
        return array_intersect_key($this->links[$locale] ?? [], array_flip($ids));
    }

    public function all(string $locale): array
    {
        return array_values($this->links[$locale] ?? []);
    }
}
