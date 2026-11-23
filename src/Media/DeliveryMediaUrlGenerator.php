<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaUrlGenerator;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Site\Contract\SiteVisibility;
use SightPress\Core\Tenant\Site;

/**
 * Where a site's files are fetched from:
 *
 * - Published, public site: the CDN host when there is one, with the file's checksum in the address.
 * - Published, members-only site with a CDN or a bucket: a signed address that expires. Pages are only rendered for
 *   people who may read them.
 * - Unpublished: always the site's own address, where the session is checked. It is signed only by
 *   {@see temporaryUrl()}, for someone already allowed to see the file, so a page can never leak a working link.
 */
final readonly class DeliveryMediaUrlGenerator implements MediaUrlGenerator
{
    private const int CHECKSUM_LENGTH = 16;

    public function __construct(
        private Site $site,
        private SiteSettings $settings,
        private MediaUrlSigner $signer,
        private OriginMediaUrlGenerator $origin = new OriginMediaUrlGenerator(),
    ) {
    }

    public function url(MediaFile $file, ?int $width = null): string
    {
        $path = $this->origin->url($file, $width);
        if (!$file->isPublished() || !$this->site->storage->signsPrivateUrls()) {
            return $file->isPublished() ? $this->onCdn($path, $file) : $path;
        }
        if (SiteVisibility::isMembersOnly($this->settings)) {
            if (!$this->signer->isAvailable()) {
                return $path;
            }

            return ($this->site->storage->cdnUrl ?? '') . $path . '?' . $this->signer->query($file->key(), $this->width($file, $width), MediaUrlSigner::MEMBER_TTL);
        }

        return $this->onCdn($path, $file);
    }

    public function siteUrl(MediaFile $file): string
    {
        return $this->origin->url($file);
    }

    public function temporaryUrl(MediaFile $file, ?int $width = null): string
    {
        $path = $this->origin->url($file, $width);
        if (!$this->site->storage->signsPrivateUrls() || !$this->signer->isAvailable()) {
            return $path;
        }

        return $path . '?' . $this->signer->query($file->key(), $this->width($file, $width), MediaUrlSigner::PREVIEW_TTL);
    }

    public function srcset(MediaFile $file): string
    {
        $width = $file->width();
        if ($width === null) {
            return '';
        }
        $candidates = array_map(fn (int $variant): string => $this->url($file, $variant) . ' ' . $variant . 'w', $file->variantWidths());
        $candidates[] = $this->url($file) . ' ' . $width . 'w';

        return implode(', ', $candidates);
    }

    private function onCdn(string $path, MediaFile $file): string
    {
        $cdn = $this->site->storage->cdnUrl;

        return $cdn === null ? $path : $cdn . $path . '?v=' . substr($file->checksum(), 0, self::CHECKSUM_LENGTH);
    }

    /**
     * The width the address really serves: one the file has no copy for gives the original.
     */
    private function width(MediaFile $file, ?int $width): ?int
    {
        return $width !== null && in_array($width, $file->variantWidths(), true) ? $width : null;
    }
}
