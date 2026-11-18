<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

use SightPress\Core\Tenant\SiteContext;
use SightPress\Core\Tenant\Site;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is looking at the page, as far as display goes: their language and time zone. Signed-in people use their own;
 * anonymous visitors get the site's defaults. Dates are stored in UTC and converted only for display.
 */
final readonly class Viewer
{
    /**
     * @param non-empty-string $locale   for example `en-GB`
     * @param non-empty-string $timezone IANA time zone, for example `Europe/London`
     */
    public function __construct(
        public string $locale,
        public string $timezone,
    ) {
    }

    /**
     * Who an email is for: their own language and time zone, or the site's (ADR-0007: then en-GB, through the
     * catalogues' fallback).
     */
    public static function recipient(?ViewerPreferences $preferences, Site $site): self
    {
        $locale = $preferences->locale ?? '';
        $timezone = $preferences->timezone ?? '';

        return new self($locale === '' ? $site->defaultLocale : $locale, $timezone === '' ? $site->defaultTimezone : $timezone);
    }

    /**
     * The viewer set on the request, or the default with the signed-in person's {@see ViewerPreferences} applied. On
     * a site, the default is the site's language and time zone.
     */
    public static function fromRequest(ServerRequestInterface $request, self $default): self
    {
        $viewer = $request->getAttribute(self::class);
        if ($viewer instanceof self) {
            return $viewer;
        }
        $context = SiteContext::fromRequest($request);
        if ($context !== null) {
            $site = $context->container->has(Site::class) ? $context->container->get(Site::class) : null;
            $site = $site instanceof Site ? $site : $context->site;
            $default = new self($site->defaultLocale, $site->defaultTimezone);
        }
        $preferences = $request->getAttribute(ViewerPreferences::class);
        if (!$preferences instanceof ViewerPreferences) {
            return $default;
        }

        $locale = $preferences->locale ?? '';
        $timezone = $preferences->timezone ?? '';

        return new self($locale === '' ? $default->locale : $locale, $timezone === '' ? $default->timezone : $timezone);
    }
}
