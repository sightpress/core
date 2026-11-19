<?php

declare(strict_types=1);

namespace SightPress\Core\Search\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Search\SearchModule;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\EnabledModules;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;

/**
 * What the `site:search…` commands share: they work on one site that exists and has search on, in one of its
 * languages when `--locale` is given.
 */
abstract readonly class SiteSearchCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    /**
     * @param non-empty-string|null $locale the locale asked for, one of the site's
     */
    abstract protected function work(Site $site, ContainerInterface $services, ?string $locale, CommandInput $input, CommandOutput $output): int;

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        $services = $this->containers->forSite($site);
        if (!Services::get($services, EnabledModules::class)->has(SearchModule::ID)) {
            $output->error(sprintf('Search is off for the site "%s". Turn it on with: site:module:enable %s %s', $slug, $slug, SearchModule::ID));

            return self::FAILURE;
        }
        $site = Services::get($services, Site::class);
        $locale = trim((string) $input->option('locale'));
        if ($locale !== '' && !in_array($locale, $site->locales, true)) {
            $output->error(sprintf('The site "%s" isn\'t written in "%s". Its languages are: %s.', $slug, $locale, implode(', ', $site->locales)));

            return self::FAILURE;
        }

        return $this->work($site, $services, $locale === '' ? null : $locale, $input, $output);
    }
}
