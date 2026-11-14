<?php

declare(strict_types=1);

namespace SightPress\Core\Module;

use SightPress\Core\Site\SiteModule;

/**
 * The static list of core's own feature modules (ADR-0009). Each feature (users, roles, pages, blocks, media …)
 * adds its `<Context>Module` here when it is built; they register in dependency order, not list order.
 */
final class CoreModules
{
    /**
     * @return list<ModuleCandidate>
     */
    public static function candidates(): array
    {
        return [
            new ModuleCandidate(new SiteModule(), 'sightpress/core'),
        ];
    }
}
