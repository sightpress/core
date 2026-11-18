<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

/**
 * A language was removed from the site. What was written in it is kept, but nobody can reach it until the language is
 * added again; modules forget what pointed at it (people who chose it get the site's default language again).
 */
final readonly class LocaleRemoved
{
    /**
     * @param non-empty-string $locale for example `pt-PT`
     */
    public function __construct(
        public string $locale,
        public ?int $actorId,
    ) {
    }
}
