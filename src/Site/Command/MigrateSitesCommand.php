<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Command;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteMigrator;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;
use Throwable;

/**
 * `site:migrate <slug>` or `site:migrate --all`: brings site databases up to date with the modules that are
 * installed (for example after an update). With `--all` every site that isn't deleted is migrated, and one that
 * fails doesn't stop the others.
 */
final readonly class MigrateSitesCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteMigrator $migrator,
    ) {
    }

    public function name(): string
    {
        return 'site:migrate';
    }

    public function description(): string
    {
        return 'Runs the database migrations a site doesn\'t have yet: give a slug, or --all for every site.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site (leave out with --all).'),
            new CommandParameter('all', 'Migrate every site that isn\'t deleted.', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = $input->argument('slug');
        $all = $input->flag('all');
        if (($slug === null) === !$all) {
            $output->error('Give the slug of one site, or --all, but not both.');

            return self::INVALID;
        }

        if ($all) {
            $sites = array_values(array_filter($this->sites->all(), static fn (Site $site): bool => $site->status !== SiteStatus::Deleted));
            if ($sites === []) {
                $output->line('There are no sites to migrate.');

                return self::SUCCESS;
            }
        } else {
            $site = $this->sites->find((string) $slug);
            if ($site === null) {
                $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

                return self::FAILURE;
            }
            $sites = [$site];
        }

        $failed = 0;
        foreach ($sites as $site) {
            try {
                $count = count($this->migrator->migrate($site));
                $output->line(match ($count) {
                    0 => sprintf('%s: already up to date.', $site->slug),
                    1 => sprintf('%s: ran 1 migration.', $site->slug),
                    default => sprintf('%s: ran %d migrations.', $site->slug, $count),
                });
            } catch (Throwable $exception) {
                ++$failed;
                $output->error(sprintf('%s: the migrations failed: %s', $site->slug, $exception->getMessage()));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
