<?php

declare(strict_types=1);

namespace SightPress\Core\Assistant;

use SightPress\Core\Infrastructure\Assistant\AssistantExtensions;
use SightPress\Core\Template\Message;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Assistant\AssistantConnection;
use SightPress\Module\Exception\InvalidValueException;

/**
 * Works out what a site's assistant uses (ADR-0040). The site's own provider, model, base URL and key win when the
 * tenant allows keys of its own (`assistant.site_keys`); otherwise, and for whatever the site leaves empty, the
 * tenant's default applies (its key from `ASSISTANT_API_KEY`), as long as it is the same provider. The budget is
 * the site's when it has one, else the tenant's.
 */
final readonly class AssistantSetup
{
    public function __construct(
        private AssistantSettings $settings,
        private AssistantExtensions $extensions,
        private TenantConfig $config,
    ) {
    }

    public function sitesMayChoose(): bool
    {
        return $this->config->assistant->siteKeys;
    }

    public function configuration(): AssistantConfiguration
    {
        $tenant = $this->config->assistant;
        $own = $tenant->siteKeys ? $this->settings->provider() : null;
        $providerId = $own ?? $tenant->provider;
        $inherits = $providerId !== null && $providerId === $tenant->provider;
        $model = ($tenant->siteKeys ? $this->settings->model() : null) ?? ($inherits ? $tenant->model : null);
        $baseUrl = ($tenant->siteKeys ? $this->settings->baseUrl() : null) ?? ($inherits ? $tenant->baseUrl : null);
        $key = ($tenant->siteKeys ? $this->settings->key() : null) ?? ($inherits ? $tenant->apiKey : null);
        $budget = $this->settings->budget() ?? $tenant->monthlyBudget;
        $source = $own !== null ? AssistantConfiguration::SITE : AssistantConfiguration::TENANT;

        $provider = $providerId === null ? null : $this->extensions->provider($providerId);
        $problem = match (true) {
            $providerId === null => new Message('No AI service is chosen yet.'),
            $provider === null => new Message('The chosen AI service ({provider}) isn’t available any more. Choose another one.', ['provider' => $providerId]),
            $model === null => new Message('No model is chosen yet.'),
            $key === null => new Message('There is no API key for {provider} yet.', ['provider' => $provider->label()]),
            $provider->needsBaseUrl() && $baseUrl === null => new Message('{provider} needs the address of the service.', ['provider' => $provider->label()]),
            default => null,
        };
        $connection = null;
        if ($problem === null && $key !== null) {
            try {
                $connection = new AssistantConnection($key, $baseUrl);
            } catch (InvalidValueException) {
                $problem = new Message('The address of the AI service isn’t valid. Enter it again.');
            }
        }

        return new AssistantConfiguration($provider, $model, $connection, $budget, $source, $problem);
    }
}
