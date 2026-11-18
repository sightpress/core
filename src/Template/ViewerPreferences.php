<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

/**
 * What a signed-in person chose for display: a language and a time zone, and for the admin panel less motion and
 * larger text. Where they chose nothing, the defaults apply ({@see Viewer::fromRequest()}).
 */
final readonly class ViewerPreferences
{
    /**
     * @param ?string $locale        for example `pt-BR`; null or empty for the default
     * @param ?string $timezone      IANA time zone; null or empty for the default
     * @param bool    $reducedMotion the admin panel without animations, transitions or smooth scrolling
     * @param bool    $largerText    the admin panel's text a size larger
     */
    public function __construct(
        public ?string $locale = null,
        public ?string $timezone = null,
        public bool $reducedMotion = false,
        public bool $largerText = false,
    ) {
    }
}
