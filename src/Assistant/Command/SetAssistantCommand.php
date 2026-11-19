<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant\Command;

use Psr\Container\ContainerInterface;
use SightPress\Core\Assistant\AssistantError;
use SightPress\Core\Assistant\AssistantSettings;
use SightPress\Core\Assistant\AssistantSetup;
use SightPress\Core\Assistant\Http\EndpointGuard;
use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Infrastructure\Container\Services;
use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\SiteContainerFactory;
use SightPress\Core\Tenant\SiteRepository;
use SightPress\Module\Console\CommandInput;
use SightPress\Module\Console\CommandOutput;
use SightPress\Module\Console\CommandParameter;
use SightPress\Module\Console\CommandParameterType;

/**
 * `site:assistant:set <slug> --provider= --model= [--base-url=] [--key-stdin]`: chooses the site's AI service and
 * model, and with `--key-stdin` reads its API key from standard input (stored encrypted; without it the current key
 * is kept). Only when the tenant lets sites use keys of their own.
 */
final readonly class SetAssistantCommand extends SiteAssistantCommand
{
    public function __construct(
        SiteRepository $sites,
        SiteContainerFactory $containers,
        private KeyInput $keys,
    ) {
        parent::__construct($sites, $containers);
    }

    public function name(): string
    {
        return 'site:assistant:set';
    }

    public function description(): string
    {
        return 'Chooses the AI service and model of a site\'s assistant, and reads its API key from standard input with --key-stdin.';
    }

    public function parameters(): array
    {
        return [
            new CommandParameter('slug', 'Slug of the site:', required: true),
            new CommandParameter('provider', 'AI service: anthropic, openai, mistral or openai_compatible (or a module\'s)', CommandParameterType::Option, required: true, label: 'AI service'),
            new CommandParameter('model', 'Model of the AI service', CommandParameterType::Option, required: true, label: 'Model'),
            new CommandParameter('base-url', 'Address of an OpenAI-compatible service, for example https://ai.example.org/v1', CommandParameterType::Option),
            new CommandParameter('key-stdin', 'Read the API key from standard input (the first line), for example: printf %s "$KEY" | bin/sightpress site:assistant:set …', CommandParameterType::Flag),
        ];
    }

    protected function work(ContainerInterface $services, Site $site, CommandInput $input, CommandOutput $output): int
    {
        if (!Services::get($services, AssistantSetup::class)->sitesMayChoose()) {
            $output->error('Your organisation chooses the AI service for every site (tenant setting assistant.site_keys is false).');

            return self::FAILURE;
        }
        $extensions = Services::get($services, AssistantExtensions::class);
        $providerId = trim((string) $input->option('provider'));
        $provider = $extensions->provider($providerId);
        if ($provider === null) {
            $output->error(sprintf('There is no AI service "%s". Choose one of: %s.', $providerId, implode(', ', array_keys($extensions->providers()))));

            return self::INVALID;
        }
        $model = trim((string) $input->option('model'));
        if ($provider->models() !== [] && !in_array($model, $provider->models(), true)) {
            $output->error(sprintf('%s has no model "%s". Choose one of: %s.', $provider->label(), $model, implode(', ', $provider->models())));

            return self::INVALID;
        }
        $baseUrl = trim((string) $input->option('base-url'));
        if ($provider->needsBaseUrl()) {
            $problem = $baseUrl === '' ? sprintf('%s needs --base-url, the address of the service.', $provider->label()) : Services::get($services, EndpointGuard::class)->problem($baseUrl);
            if ($problem !== null) {
                $output->error($problem);

                return self::INVALID;
            }
        } elseif ($baseUrl !== '') {
            $output->error(sprintf('%s doesn\'t take a --base-url.', $provider->label()));

            return self::INVALID;
        }
        $key = null;
        if ($input->flag('key-stdin')) {
            $key = $this->keys->read();
            if ($key === '') {
                $output->error('No key was read from standard input. Pipe it in, for example: printf %s "$KEY" | bin/sightpress site:assistant:set …');

                return self::INVALID;
            }
        }

        $settings = Services::get($services, AssistantSettings::class);
        $changed = $settings->connect($providerId, $model, $baseUrl === '' ? null : $baseUrl, $key, null);
        $output->line($changed === []
            ? sprintf('Nothing changed for "%s".', $site->slug)
            : sprintf('The assistant of "%s" now uses %s with %s.', $site->slug, $provider->label(), $model));
        $masked = $settings->maskedKey();
        $problem = Services::get($services, AssistantSetup::class)->configuration()->problem;
        $output->line($masked === null ? 'The site has no key of its own.' : sprintf('API key: %s', $masked));
        if ($problem !== null) {
            $output->line('Not ready yet: ' . AssistantError::english($problem));
        } else {
            $output->line(sprintf('Check it with: site:assistant:test %s', $site->slug));
        }

        return self::SUCCESS;
    }
}
