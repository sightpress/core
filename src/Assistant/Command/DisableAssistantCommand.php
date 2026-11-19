<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Command;

use SightPress\Core\Assistant\AssistantModule;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteModuleException;
use SightPress\Core\Tenant\SiteModules;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Core\Tenant\SiteStatus;
use SightPress\Module\Console\Command;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:assistant:disable <slug> [--remove-key]`: turns the AI assistant off for a site (like the Features screen);
 * its settings and usage are kept. `--remove-key` also forgets the site's API key.
 */
final readonly class DisableAssistantCommand implements Command
{
    public function __construct(
        private SiteRepository $sites,
        private SiteModules $modules,
        private SiteContainerFactory $containers,
    ) {
    }

    public function name(): string
    {
        return 'site:assistant:disable';
    }

    public function description(): string
    {
        return 'Turns the AI assistant off for a site; with --remove-key it also forgets the site\'s API key.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('remove-key', 'Also forget the site\'s API key', CommandParameterType::Flag),
        ];
    }

    public function run(CommandInput $input, CommandOutput $output): int
    {
        $slug = trim((string) $input->argument('slug'));
        $site = $this->sites->find($slug);
        if ($site === null || $site->status === SiteStatus::Deleted) {
            $output->error(sprintf('There is no site "%s". See the sites with site:list.', $slug));

            return self::FAILURE;
        }
        if ($input->flag('remove-key')) {
            $settings = Services::get($this->containers->forSite($site), AssistantSettings::class);
            $output->line($settings->removeKey(null) ? sprintf('The API key of "%s" is removed.', $slug) : sprintf('"%s" has no API key of its own.', $slug));
        }
        try {
            $off = $this->modules->turnOff($site, AssistantModule::ID, null);
        } catch (SiteModuleException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }
        $output->line($off ? sprintf('The AI assistant is off for "%s". Its settings are kept.', $slug) : sprintf('The AI assistant was already off for "%s".', $slug));

        return self::SUCCESS;
    }
}
