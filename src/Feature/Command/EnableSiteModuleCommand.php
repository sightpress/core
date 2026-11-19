<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Module\Console\CommandInput;

/**
 * `site:module:enable <slug> <module>`: turns an optional module on for a site, after running its migrations.
 */
final readonly class EnableSiteModuleCommand extends SiteModuleCommand
{
    public function name(): string
    {
        return 'site:module:enable';
    }

    public function description(): string
    {
        return 'Turns an optional module (feature) on for a site, after running its database migrations.';
    }

    protected function change(SiteModules $modules, Site $site, CommandInput $input): array
    {
        $slug = $site->slug;
        $id = self::module($input);
        $name = $modules->get($site, $id)->name;
        if (!$modules->turnOn($site, $id, null)) {
            return [[sprintf('%s (%s) is already on for "%s".', $name, $id, $slug)], null];
        }

        return [[sprintf('%s (%s) is on for "%s".', $name, $id, $slug)], sprintf('Site "%s" module "%s" turned on.', $slug, $id)];
    }
}
