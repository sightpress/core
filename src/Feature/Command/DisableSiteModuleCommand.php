<?php

declare(strict_types=1);

namespace SightPress\Core\Feature\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:module:disable <slug> <module> [--purge-data]`: turns an optional module off for a site. Its data is kept,
 * unless `--purge-data` deletes it too, for good.
 */
final readonly class DisableSiteModuleCommand extends SiteModuleCommand
{
    public function name(): string
    {
        return 'site:module:disable';
    }

    public function description(): string
    {
        return 'Turns an optional module (feature) off for a site; its data is kept unless --purge-data is given.';
    }

    public function parameters(): array
    {
        return [
            ...parent::parameters(),
            new CommandParameter('purge-data', 'Also delete the module\'s data from the site\'s database, for good.', CommandParameterType::Flag),
        ];
    }

    protected function change(SiteModules $modules, Site $site, CommandInput $input): array
    {
        $slug = $site->slug;
        $id = self::module($input);
        $name = $modules->get($site, $id)->name;
        $changed = $modules->turnOff($site, $id, null);
        $purge = $input->flag('purge-data');
        $lines = [$changed
            ? sprintf('%s (%s) is off for "%s".%s', $name, $id, $slug, $purge ? '' : ' Its data is kept.')
            : sprintf('%s (%s) is already off for "%s".', $name, $id, $slug)];
        if (!$purge) {
            return [$lines, $changed ? sprintf('Site "%s" module "%s" turned off.', $slug, $id) : null];
        }

        $undone = $modules->deleteData($site, $id, null);
        $lines[] = $undone > 0
            ? sprintf('Deleted the data of %s: undid %d database migration%s.', $name, $undone, $undone === 1 ? '' : 's')
            : sprintf('%s has no data to delete.', $name);

        return [$lines, sprintf('Site "%s" module "%s" turned off%s.', $slug, $id, $undone > 0 ? ' and its data deleted' : '')];
    }
}
