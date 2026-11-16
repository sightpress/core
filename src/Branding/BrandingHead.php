<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Page\Contract\PublicPageRendering;
use SightPress\Core\Tenant\OwnerType;
use SightPress\Core\Tenant\Site;

/**
 * Adds the site's brand to every page of the public site: icons, the web app manifest and `theme-color`; Open Graph
 * and Twitter card tags, with the page's own share image when it has one (else the site's), always as an absolute,
 * versioned address; and the schema.org nodes of who runs the site (an `Organization` or a `Person`, with the logo)
 * and of the `WebSite`, which other nodes can refer to by `@id`. An uploaded logo replaces the site's name at the top
 * of the page (`branding_logo`).
 */
final readonly class BrandingHead
{
    public function __construct(
        private BrandingService $branding,
        private Site $site,
        private MediaLibrary $media,
        private MediaUrlGenerator $urls,
    ) {
    }

    public function __invoke(PublicPageRendering $event): void
    {
        $state = $this->branding->state();
        if ($state->version === '') {
            $state = $this->branding->regenerate();
        }
        $base = rtrim($event->baseUrl, '/');
        $file = static fn (string $path): string => $base . $path . '?v=' . rawurlencode($state->version);
        $name = $this->site->name;
        $description = $event->description ?? $event->summary;

        $tags = [
            ['link', ['rel' => 'icon', 'href' => $file('/favicon.ico'), 'sizes' => '32x32']],
        ];
        if ($state->asset(BrandAsset::Icon) !== null && !$state->isUploaded(BrandAsset::Icon)) {
            $tags[] = ['link', ['rel' => 'icon', 'href' => $file('/icon.svg'), 'type' => 'image/svg+xml']];
        }
        $tags[] = ['link', ['rel' => 'apple-touch-icon', 'href' => $file('/apple-touch-icon.png')]];
        $tags[] = ['link', ['rel' => 'manifest', 'href' => '/site.webmanifest']];
        $tags[] = ['meta', ['name' => 'theme-color', 'content' => BrandColours::BACKGROUND]];

        [$image, $width, $height, $alt] = $this->shareImage($event, $state, $file);
        foreach ([
            'og:type' => $event->home ? 'website' : 'article',
            'og:site_name' => $name,
            'og:title' => $event->title,
            'og:description' => $description,
            'og:url' => $base . $event->url,
            'og:locale' => str_replace('-', '_', $event->locale),
            'og:image' => $image,
            'og:image:width' => $width,
            'og:image:height' => $height,
            'og:image:alt' => $alt,
        ] as $property => $content) {
            if ($content !== null) {
                $tags[] = ['meta', ['property' => $property, 'content' => (string) $content]];
            }
        }
        foreach (['twitter:card' => 'summary_large_image', 'twitter:title' => $event->title, 'twitter:description' => $description, 'twitter:image' => $image, 'twitter:image:alt' => $alt] as $metaName => $content) {
            if ($content !== null) {
                $tags[] = ['meta', ['name' => $metaName, 'content' => $content]];
            }
        }

        $html = array_map(static fn (array $tag): string => self::tag($tag[0], $tag[1]), $tags);
        // A data block, never run, so it needs no CSP nonce; leaving the nonce out keeps the page the same for every
        // visitor, so its ETag holds.
        $html[] = sprintf(
            '<script type="application/ld+json">%s</script>',
            json_encode($this->graph($base, $state, $file), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
        );
        $event->addHead(implode("\n", $html));

        $logo = $state->asset(BrandAsset::Logo);
        if ($logo !== null && $state->isUploaded(BrandAsset::Logo)) {
            $event->set('branding_logo', [
                'url' => $file('/branding/' . $logo['file']),
                'alt' => $state->alt(BrandAsset::Logo) ?? $name,
                'width' => $logo['width'],
                'height' => $logo['height'],
            ]);
        }
    }

    /**
     * The page's own share image (a published picture of the media library), or the site's.
     *
     * @param callable(string): string $file
     *
     * @return array{string, ?int, ?int, string}
     */
    private function shareImage(PublicPageRendering $event, BrandingState $state, callable $file): array
    {
        $own = $event->socialImageId === null ? null : $this->media->file($event->socialImageId);
        if ($own !== null && $own->isPublished() && $own->kind() === MediaKind::Image) {
            $url = $this->urls->url($own);

            return [
                str_starts_with($url, '/') ? rtrim($event->baseUrl, '/') . $url : $url,
                $own->width(),
                $own->height(),
                $own->altText($event->locale) ?? $event->title,
            ];
        }
        $share = $state->asset(BrandAsset::ShareImage) ?? ['file' => 'share.png', 'width' => BrandImages::SHARE_WIDTH, 'height' => BrandImages::SHARE_HEIGHT];

        return [$file('/branding/' . $share['file']), $share['width'], $share['height'], $state->alt(BrandAsset::ShareImage) ?? $this->site->name];
    }

    /**
     * @param callable(string): string $file
     *
     * @return array<string, mixed>
     */
    private function graph(string $base, BrandingState $state, callable $file): array
    {
        $person = $this->site->ownerType === OwnerType::Person;
        $owner = ['@type' => $person ? 'Person' : 'Organization', '@id' => $base . '/#' . ($person ? 'person' : 'organization'), 'name' => $this->site->name, 'url' => $base . '/'];
        $logo = $state->asset(BrandAsset::Logo);
        if ($logo !== null) {
            $owner[$person ? 'image' : 'logo'] = ['@type' => 'ImageObject', 'url' => $file('/branding/' . $logo['file']), 'width' => $logo['width'], 'height' => $logo['height']];
        }

        return ['@context' => 'https://schema.org', '@graph' => [
            $owner,
            ['@type' => 'WebSite', '@id' => $base . '/#website', 'name' => $this->site->name, 'url' => $base . '/', 'inLanguage' => $this->site->defaultLocale, 'publisher' => ['@id' => $owner['@id']]],
        ]];
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function tag(string $name, array $attributes): string
    {
        $html = '<' . $name;
        foreach ($attributes as $attribute => $value) {
            $html .= sprintf(' %s="%s"', $attribute, htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
        }

        return $html . '>';
    }
}
