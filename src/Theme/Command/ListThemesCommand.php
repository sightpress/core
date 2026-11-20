<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Command;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Theme\SiteTheme;
use SightPress\Core\Theme\ThemeRegistry;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * `theme:list`: the installed themes with their version, whether sites may use them, and the sites that use each, so an
 * operator knows before removing a package. Sites whose theme isn't installed any more are listed too.
 */
final readonly class ListThemesCommand implements Command
{
    public function __construct(
        private ThemeRegistry $registry,
        private TenantConfig $config,
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'theme:list';
    }

    public function description(): string
    {
        return 'Lists the installed themes, whether sites may use them, and the sites that use each.';
    }

    public function parameters(): array
    {
        return [];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $using = [];
        foreach ($this->sites->all() as $site) {
            if ($site->status !== SiteStatus::Deleted) {
                $using[Services::get($this->containers->forSite($site), SiteTheme::class)->chosen()][] = $site->slug;
            }
        }

        foreach ($this->registry->all() as $theme) {
            $manifest = $theme->manifest();
            $unavailable = $this->registry->unavailable($theme);
            $allowed = $this->config->themes->allows($manifest->id);
            $status = $unavailable === null ? ($allowed ? 'available' : 'installed, not allowed (tenant setting "themes.allowed")') : 'unavailable: ' . $unavailable;
            $sites = $using[$manifest->id] ?? [];
            unset($using[$manifest->id]);
            $output->line(sprintf(
                '%s (%s) %s, %s%s; %s.',
                $manifest->id,
                $manifest->name,
                $manifest->version,
                $status,
                $manifest->id === $this->config->themes->default ? ', the default for new sites' : '',
                $sites === [] ? 'no site uses it' : 'used by ' . implode(', ', $sites),
            ));
        }
        foreach ($using as $id => $sites) {
            $output->error(sprintf('%s is not installed, but %s chose it; they are shown in the default theme.', $id, implode(', ', $sites)));
        }

        return self::SUCCESS;
    }
}
