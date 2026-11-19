<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Assistant\Assistant;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\Site;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;

/**
 * `site:assistant:test <slug>`: sends a tiny request to the site's AI service and says whether it works, or in plain
 * words why not. It costs a few tokens, counted like any other use.
 */
final readonly class TestAssistantCommand extends SiteAssistantCommand
{
    public function name(): string
    {
        return 'site:assistant:test';
    }

    public function description(): string
    {
        return 'Tries the connection of a site\'s AI assistant with a tiny request.';
    }

    public function parameters(): array
    {
        return [new CommandParameter('slug', 'Slug of the site:', required: true)];
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        $configuration = Services::get($services, AssistantSetup::class)->configuration();
        try {
            $response = Services::get($services, Assistant::class)->test();
        } catch (AssistantError $error) {
            $output->error(sprintf('The connection doesn\'t work: %s', $error->getMessage()));

            return self::FAILURE;
        }
        $output->line(sprintf(
            'The connection works: %s answered with %s (%d tokens, %s).',
            $configuration->provider?->label() ?? '',
            $response->model,
            $response->usage->total(),
            $configuration->source === 'site' ? 'the site\'s own key' : 'your organisation\'s key',
        ));

        return self::SUCCESS;
    }
}
