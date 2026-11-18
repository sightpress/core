<?php

declare(strict_types=1);

namespace SightPress\Core\Site\Contract;

/**
 * Who may read the site (site setting `site.visibility`, D24): `public` (the default) — published pages are open to
 * everyone unless a page is for members only — or `members` — every page needs a signed-in account on the site, and
 * anonymous visitors only reach the sign-in, sign-up, code and password pages, the site's files (styles, icons, logo)
 * and `robots.txt`. Nothing of a members-only site is indexable.
 */
final class SiteVisibility
{
    public const string SETTING = 'site.visibility';

    public const string PUBLIC = 'public';

    public const string MEMBERS = 'members';

    public static function isMembersOnly(SiteSettings $settings): bool
    {
        return $settings->get(self::SETTING) === self::MEMBERS;
    }

    /**
     * Whether everyone may read a published page: it is public and so is the site.
     */
    public static function isOpenToEveryone(bool $pageIsPublic, SiteSettings $settings): bool
    {
        return $pageIsPublic && !self::isMembersOnly($settings);
    }
}
