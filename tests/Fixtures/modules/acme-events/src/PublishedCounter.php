<?php

declare(strict_types=1);

namespace Acme\Events;

use SightPress\Module\Event\PageTransitioned;

/**
 * Listens to a core event without depending on core: counts pages put on the site, in the site's audit log.
 */
final readonly class PublishedCounter
{
    public function __construct(private \SightPress\Module\Audit\AuditTrail $audit)
    {
    }

    public function __invoke(PageTransitioned $event): void
    {
        if ($event->published()) {
            $this->audit->record(new \SightPress\Module\Audit\AuditRecord('acme.events.page_seen', sprintf('Noticed that page %d was published.', $event->pageId), $event->actorId, 'page', $event->pageId));
        }
    }
}
