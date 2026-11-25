<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Template\Message;
use SightPress\Core\Infrastructure\Sms\SmsProviders;
use SightPress\Core\Tenant\ModuleTurnOnCheck;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Exception\InvalidValueException;
use SightPress\Module\Sms\SmsConnection;

/**
 * Works out what a site sends text messages with (ADR-0069): the site's own provider when it has one (set by the
 * operator with `site:sms:set`, or by its admins when the tenant allows it), otherwise the tenant's default with its
 * key from `SMS_API_KEY`. The budget is the site's when it has one, else the tenant's. As a {@see ModuleTurnOnCheck},
 * it stops text messages from being turned on for a site that can't send any.
 */
final readonly class SmsSetup implements ModuleTurnOnCheck
{
    public function __construct(
        private SmsSettings $settings,
        private SmsProviders $providers,
        private TenantConfig $config,
    ) {
    }

    /**
     * Whether the site's admins may set up the provider themselves (`sms.site_providers`).
     */
    public function sitesMayChoose(): bool
    {
        return $this->config->sms->siteProviders;
    }

    public function configuration(): SmsConfiguration
    {
        $tenant = $this->config->sms;
        $own = $this->settings->provider();
        $providerId = $own ?? $tenant->provider;
        $site = $own !== null;
        $key = $site ? $this->settings->key() : $tenant->key;
        $budget = $this->settings->budget() ?? $tenant->monthlyBudget;
        $provider = $providerId === null ? null : $this->providers->get($providerId);
        $problem = match (true) {
            $providerId === null => new Message('No text message provider is set up for this site yet.'),
            $provider === null => new Message('The text message provider ({provider}) isn’t available any more.', ['provider' => $providerId]),
            $key === null => new Message('There is no key for {provider} yet.', ['provider' => $provider->label()]),
            default => null,
        };
        $connection = null;
        if ($problem === null && $key !== null) {
            try {
                $connection = $site
                    ? new SmsConnection($key, $this->settings->account(), $this->settings->region(), $this->settings->sender())
                    : new SmsConnection($key, $tenant->account, $tenant->region, $tenant->sender);
            } catch (InvalidValueException) {
                $problem = new Message('The text message sender or region isn’t valid. Set the provider up again.');
            }
        }

        return new SmsConfiguration($provider, $connection, $budget, $tenant->reservedForCodes, $site ? SmsConfiguration::SITE : SmsConfiguration::TENANT, $problem);
    }

    public function problem(): ?Message
    {
        $problem = $this->configuration()->problem;

        return $problem === null ? null : new Message('Text messages can’t be turned on yet: {problem} Ask whoever runs your sites to set up a provider.', ['problem' => $problem]);
    }
}
