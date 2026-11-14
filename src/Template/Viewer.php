<?php

declare(strict_types=1);

namespace SightPress\Core\Template;

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

    public static function fromRequest(ServerRequestInterface $request, self $default): self
    {
        $viewer = $request->getAttribute(self::class);

        return $viewer instanceof self ? $viewer : $default;
    }
}
