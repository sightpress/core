<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use ArrayObject;
use SightPress\Core\Tenant\Site;

/**
 * A listener that needs a site service: it writes the site's slug into the event.
 */
final readonly class SiteNameListener
{
    public function __construct(private Site $site)
    {
    }

    /**
     * @param ArrayObject<int, string> $event
     */
    public function __invoke(ArrayObject $event): void
    {
        $event->append($this->site->slug);
    }
}
