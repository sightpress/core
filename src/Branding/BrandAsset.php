<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

/**
 * The pictures that make up a site's brand.
 *
 * - **Logo**: shown at the top of every page instead of the site's name, and given to search engines. SVG, PNG,
 *   JPEG or WebP, from 1:1 to 8:1 wide. Without one, the site name is shown as text, and a wordmark is generated
 *   for search engines.
 * - **Icon**: the square picture browsers show in tabs and on home screens. PNG, JPEG or WebP of at least
 *   512 × 512 pixels. Without one, an icon with the site's initials is generated.
 * - **Share image**: the picture social networks and messaging apps show with a link to the site. PNG, JPEG or WebP
 *   of 1200 × 630 pixels, or larger in the same proportions. Without one, a picture with the site's name is generated.
 */
enum BrandAsset: string
{
    case Logo = 'logo';
    case Icon = 'icon';
    case ShareImage = 'share_image';

    public function label(): string
    {
        return match ($this) {
            self::Logo => 'logo',
            self::Icon => 'icon',
            self::ShareImage => 'share image',
        };
    }
}
