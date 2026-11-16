<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use SightPress\Core\Tenant\TenantConfig;

/**
 * What search engines are asked to do with a page:
 *
 * | Install        | Page                       | Robots              |
 * | -------------- | -------------------------- | ------------------- |
 * | not production | any                        | `noindex, nofollow` |
 * | production     | members only               | `noindex, nofollow` |
 * | production     | public, `noindex`          | `noindex`           |
 * | production     | public                     | nothing (index)     |
 *
 * Sites that aren't enabled answer 503 / 410 before any page is rendered, and the admin, sign-in and account pages
 * always say `noindex, nofollow` themselves.
 */
final readonly class RobotsPolicy
{
    public function __construct(private TenantConfig $config)
    {
    }

    public function isProduction(): bool
    {
        return $this->config->environment->isProduction();
    }

    public function forPage(bool $public, bool $noindex): ?string
    {
        if (!$this->isProduction() || !$public) {
            return 'noindex, nofollow';
        }

        return $noindex ? 'noindex' : null;
    }
}
