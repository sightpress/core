<?php

declare(strict_types=1);

namespace SightPress\Core\Analytics\Contract;

/**
 * Someone deleted all of a site's analytics from the admin ("Delete analytics data"); the audit log records it.
 */
final readonly class AnalyticsDataDeleted
{
    public function __construct(public ?int $actorId)
    {
    }
}
