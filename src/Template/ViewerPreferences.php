<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

/**
 * What a signed-in person chose for display: a language and a time zone. Where they chose nothing, the defaults
 * apply ({@see Viewer::fromRequest()}).
 */
final readonly class ViewerPreferences
{
    /**
     * @param ?string $locale   for example `pt-BR`; null or empty for the default
     * @param ?string $timezone IANA time zone; null or empty for the default
     */
    public function __construct(
        public ?string $locale = null,
        public ?string $timezone = null,
    ) {
    }
}
