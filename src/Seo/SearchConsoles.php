<?php

declare(strict_types=1);

namespace SightPress\Core\Seo;

use SightPress\Core\Seo\Contract\SiteVerification;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * The codes search engines give a site's owner to prove the site is theirs (Google Search Console, Bing Webmaster
 * Tools, Yandex Webmaster). Each one that is set becomes a `<meta>` tag on the home page.
 */
final readonly class SearchConsoles
{
    public function __construct(private SiteSettings $settings)
    {
    }

    /**
     * @return array<string, string> meta name => code, for the codes that are set and look right
     */
    public function tags(): array
    {
        $tags = [];
        foreach (SiteVerification::CODES as $setting => $name) {
            $code = trim((string) $this->settings->get($setting));
            if (preg_match(SiteVerification::CODE_PATTERN, $code) === 1) {
                $tags[$name] = $code;
            }
        }

        return $tags;
    }
}
