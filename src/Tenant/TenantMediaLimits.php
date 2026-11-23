<?php

declare(strict_types=1);

namespace SightPress\Core\Tenant;

use InvalidArgumentException;
use SightPress\Core\Media\MediaLimits;

/**
 * `media.limits` in `config/tenant.php`: the app limits of every site, and under `max` the ceiling a site's own
 * override can't go above. A value without a ceiling can't be raised above the tenant's default.
 */
final readonly class TenantMediaLimits
{
    public MediaLimits $defaults;

    public MediaLimits $ceiling;

    public function __construct(
        SiteMediaLimits $defaults = new SiteMediaLimits(),
        SiteMediaLimits $max = new SiteMediaLimits(),
    ) {
        $this->defaults = self::apply(new MediaLimits(), $defaults);
        $this->ceiling = self::apply($this->defaults, $max);
        foreach (self::values($this->defaults) as $key => $value) {
            if ($value > self::values($this->ceiling)[$key]) {
                throw new TenantConfigException(sprintf('Tenant setting "media.limits.%s" is above its ceiling, "media.limits.max.%s".', $key, $key));
            }
        }
    }

    /**
     * @param array<mixed> $media the `media` section of the tenant settings
     */
    public static function fromArray(array $media): self
    {
        $limits = $media['limits'] ?? [];
        if (!is_array($limits)) {
            throw new TenantConfigException('Tenant setting "media.limits" must be an array.');
        }
        $max = $limits['max'] ?? [];
        unset($limits['max']);
        if (!is_array($max)) {
            throw new TenantConfigException('Tenant setting "media.limits.max" must be an array.');
        }

        try {
            return new self(SiteMediaLimits::fromArray($limits), SiteMediaLimits::fromArray($max));
        } catch (InvalidArgumentException $exception) {
            throw new TenantConfigException('Tenant setting "media.limits": ' . $exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The limits of a site: its own override over the tenant's defaults.
     */
    public function forSite(Site $site): MediaLimits
    {
        return self::apply($this->defaults, $site->mediaLimits);
    }

    /**
     * The first value of an override above the ceiling, as [key, value, ceiling]; null when all of them fit.
     *
     * @return array{string, int, int}|null
     */
    public function aboveCeiling(SiteMediaLimits $override): ?array
    {
        $ceiling = self::values($this->ceiling);
        foreach ($override->toArray() as $key => $value) {
            if ($value > $ceiling[$key]) {
                return [$key, $value, $ceiling[$key]];
            }
        }

        return null;
    }

    private static function apply(MediaLimits $base, SiteMediaLimits $override): MediaLimits
    {
        return new MediaLimits(
            $override->image ?? $base->imageBytes,
            $override->video ?? $base->videoBytes,
            $override->audio ?? $base->audioBytes,
            $override->document ?? $base->documentBytes,
            $override->pixels ?? $base->imagePixels,
        );
    }

    /**
     * @return array{image: int, video: int, audio: int, document: int, pixels: int}
     */
    private static function values(MediaLimits $limits): array
    {
        return [
            'image' => $limits->imageBytes,
            'video' => $limits->videoBytes,
            'audio' => $limits->audioBytes,
            'document' => $limits->documentBytes,
            'pixels' => $limits->imagePixels,
        ];
    }
}
