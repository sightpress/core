<?php

declare(strict_types=1);

namespace SightPress\Core\Sms;

use SightPress\Core\Tenant\Site;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Module\Sms\PhoneNumber;

/**
 * A keyed hash of a phone number (HMAC-SHA256 with the tenant's `APP_SECRET` and the site), so the log, the limits and
 * STOP replies can recognise a number without storing it. Phone numbers are few enough to guess, so a plain hash
 * would give them away.
 */
final readonly class SmsNumberHash
{
    public function __construct(private TenantConfig $config, private Site $site)
    {
    }

    public function of(PhoneNumber $number): string
    {
        return hash_hmac('sha256', $this->site->id . '|' . $number->e164, 'sightpress-sms|' . ($this->config->secret ?? ''));
    }
}
