<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:module:list <slug>`: each optional module with its id, its name and whether it is on for the site.
 */
final readonly class ListSiteModulesCommand extends SiteModuleCommand
{
    public function name(): string
    {
        return 'site:module:list';
    }

    public function description(): string
    {
        return 'Lists the optional modules (features) of a site and whether each one is on.';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    protected function change(SiteModules $modules, Site $site, CommandInput $input): array
    {
        $slug = $site->slug;
        $lines = [];
        foreach ($modules->all($site) as $feature) {
            $lines[] = sprintf(
                '%s (%s): %s%s',
                $feature->id,
                $feature->name,
                $feature->on ? 'on' : 'off',
                $feature->locked ? ', set by the tenant (config/tenant.php)' : '',
            );
        }

        return [$lines === [] ? [sprintf('The site "%s" has no optional modules: every installed module is always on.', $slug)] : $lines, null];
    }
}
