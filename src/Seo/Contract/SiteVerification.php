<?php

declare(strict_types=1);

namespace SightPress\Core\Seo\Contract;

/**
 * The settings with the codes search engines give a site's owner to prove the site is theirs, and what such a
 * code looks like, for the screens that ask for them.
 */
final class SiteVerification
{
    /** Setting => name of the `<meta>` tag on the home page. */
    public const array CODES = [
        'seo.verify_google' => 'google-site-verification',
        'seo.verify_bing' => 'msvalidate.01',
        'seo.verify_yandex' => 'yandex-verification',
    ];

    /** Letters, digits, `-` and `_`, as the consoles give them. */
    public const string CODE_PATTERN = '/^[A-Za-z0-9_-]{1,200}$/';
}
