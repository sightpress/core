<?php

declare(strict_types=1);

namespace SightPress\Core\Theme\Command;

use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Core\Theme\ThemeApplier;
use SightPress\Core\Theme\ThemeException;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:theme:apply <slug> <theme>`: applies an installed theme to a site, after checking it again ({@see ThemeApplier}).
 * The site's audit log records it, with the command line as the actor.
 */
final readonly class ApplyThemeCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:theme:apply';
    }

    public function description(): string
    {
        return 'Applies an installed theme to a site, after checking its contrast and templates.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('theme', 'Id of the theme, as theme:list shows it:', required: true, label: 'Theme'),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $id = trim((string) $input->argument('theme'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }

        try {
            $check = Services::get($this->containers->forSite($site), ThemeApplier::class)->apply($id);
        } catch (ThemeException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        $output->line(sprintf('Applied the theme "%s" to %s.', $check->name, $slug));

        return self::SUCCESS;
    }
}
