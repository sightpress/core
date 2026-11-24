<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Registration;

use SightPress\Core\Auth\Contract\DomainRegistration;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\TenantConfig;

/**
 * {@see DomainRegistration} from the tenant's `domain_registration`, the site's visibility and its own opt-out.
 */
final readonly class TenantDomainRegistration implements DomainRegistration
{
    public function __construct(
        private TenantConfig $config,
        private SiteSettings $settings,
    ) {
    }

    public function offered(): ?array
    {
        return SiteVisibility::isMembersOnly($this->settings) ? $this->config->policies->registrationDomains : null;
    }

    public function domains(): ?array
    {
        return $this->isTurnedOff() ? null : $this->offered();
    }

    public function isTurnedOff(): bool
    {
        return $this->settings->get(self::SETTING) === self::OFF;
    }
}
