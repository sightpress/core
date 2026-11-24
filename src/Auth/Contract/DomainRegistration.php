<?php

declare(strict_types=1);

namespace SightPress\Core\Auth\Contract;

/**
 * Domain registration (X-7, D9): on a members-only site, people with an email address at the organisation's domains
 * (tenant setting `domain_registration`) may make their own account even while the site's sign-up is off, with the
 * same form, emailed code, spam checks and sign-up role. A site's admins can turn it off for their site (site
 * setting {@see SETTING}: {@see TENANT}, the default, or {@see OFF}); they can't turn it on or change the domains.
 */
interface DomainRegistration
{
    public const string SETTING = 'people.domain_registration';

    /** The site follows the tenant. */
    public const string TENANT = 'tenant';

    /** The site's admins turned it off. */
    public const string OFF = 'off';

    /**
     * The organisation's domains when the tenant offers domain registration to this site (it is on for the tenant
     * and the site is members-only), whether or not the site turned it off; null otherwise. Only then can the site's
     * admins turn it off.
     *
     * @return non-empty-list<string>|null
     */
    public function offered(): ?array;

    /**
     * The domains whose addresses may register on this site now, or null when domain registration is off here.
     *
     * @return non-empty-list<string>|null
     */
    public function domains(): ?array;

    /**
     * Whether the site's admins turned it off.
     */
    public function isTurnedOff(): bool;
}
