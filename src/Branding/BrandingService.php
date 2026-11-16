<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

use League\Flysystem\FilesystemOperator;
use SightPress\Core\Site\Contract\SiteSettings;
use SightPress\Core\Tenant\Site;

/**
 * A site's logo, icon and share image: uploads them (checked, cleaned and re-encoded), puts the generated ones back
 * ({@see reset()}), and makes the generated ones again, for example after the site was renamed ({@see regenerate()}).
 * Files live in the site's storage under `branding/`; what they are is kept in the {@see BrandingState}.
 */
final readonly class BrandingService
{
    public const string FOLDER = 'branding/';

    /** Every file the module may write, so a reset or a new upload removes what it replaces. */
    private const array FILES = [
        'logo' => ['logo.svg', 'logo.png', 'wordmark.svg'],
        'icon' => ['icon-32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'favicon.ico', 'icon.svg'],
        'share_image' => ['share.png', 'share.jpg'],
    ];

    public function __construct(
        private FilesystemOperator $storage,
        private SiteSettings $settings,
        private Site $site,
        private BrandImages $images,
    ) {
    }

    public function state(): BrandingState
    {
        return BrandingState::fromJson($this->settings->get(BrandingState::SETTING));
    }

    /**
     * Uses an uploaded picture for the asset.
     *
     * @param string|null $alt for the logo and the share image: what the picture shows (the logo's defaults to the
     *                         site's name)
     */
    public function set(BrandAsset $asset, string $bytes, ?string $alt = null): BrandingState
    {
        $alt = $alt === null || trim($alt) === '' ? null : mb_substr(trim((string) preg_replace('/\s+/u', ' ', $alt)), 0, 250);
        [$files, $entry] = match ($asset) {
            BrandAsset::Logo => $this->logo($bytes),
            BrandAsset::Icon => [$this->images->iconSet($bytes), ['file' => 'icon-512.png', 'width' => 512, 'height' => 512, 'uploaded' => true]],
            BrandAsset::ShareImage => [
                ['share.jpg' => $this->images->shareImage($bytes)],
                ['file' => 'share.jpg', 'width' => BrandImages::SHARE_WIDTH, 'height' => BrandImages::SHARE_HEIGHT, 'uploaded' => true],
            ],
        };
        $this->replace($asset, $files);

        return $this->save($this->state()->with($asset, $entry, $asset === BrandAsset::Icon ? null : $alt));
    }

    /**
     * Puts the generated picture back in place of an uploaded one.
     */
    public function reset(BrandAsset $asset): BrandingState
    {
        $state = $this->state()->with($asset, null);
        [$files, $entry] = $this->generated($asset);
        $this->replace($asset, $files);

        return $this->save($state->with($asset, $entry));
    }

    /**
     * Makes every picture that wasn't uploaded again, from the site's current name.
     */
    public function regenerate(): BrandingState
    {
        $state = $this->state();
        foreach (BrandAsset::cases() as $asset) {
            if (!$state->isUploaded($asset)) {
                [$files, $entry] = $this->generated($asset);
                $this->replace($asset, $files);
                $state = $state->with($asset, $entry, $state->alt($asset));
            }
        }

        return $this->save($state);
    }

    /**
     * The contents of a branding file, or null when the site doesn't have it.
     */
    public function read(string $file): ?string
    {
        $path = self::FOLDER . $file;
        if (!in_array($file, array_merge(...array_values(self::FILES)), true) || !$this->storage->fileExists($path)) {
            return null;
        }

        return $this->storage->read($path);
    }

    /**
     * An SVG logo is cleaned, a raster one encoded again as PNG.
     *
     * @return array{array<string, string>, array{file: string, width: int, height: int, uploaded: bool}}
     */
    private function logo(string $bytes): array
    {
        if (str_contains(BrandImages::mimeType($bytes), 'svg') || preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg[\s>]/s', $bytes) === 1) {
            [$svg, $width, $height] = SvgCleaner::clean($bytes);
            BrandImages::checkLogoRatio($width, $height);

            return [['logo.svg' => $svg], ['file' => 'logo.svg', 'width' => (int) round($width), 'height' => (int) round($height), 'uploaded' => true]];
        }
        [$png, $width, $height] = $this->images->rasterLogo($bytes);

        return [['logo.png' => $png], ['file' => 'logo.png', 'width' => $width, 'height' => $height, 'uploaded' => true]];
    }

    /**
     * @return array{array<string, string>, array{file: string, width: int, height: int, uploaded: bool}}
     */
    private function generated(BrandAsset $asset): array
    {
        return match ($asset) {
            BrandAsset::Logo => $this->wordmark(),
            BrandAsset::Icon => [$this->images->initialsIconSet($this->site->name), ['file' => 'icon-512.png', 'width' => 512, 'height' => 512, 'uploaded' => false]],
            BrandAsset::ShareImage => [
                ['share.png' => $this->images->generatedShareImage($this->site->name)],
                ['file' => 'share.png', 'width' => BrandImages::SHARE_WIDTH, 'height' => BrandImages::SHARE_HEIGHT, 'uploaded' => false],
            ],
        };
    }

    /**
     * @return array{array<string, string>, array{file: string, width: int, height: int, uploaded: bool}}
     */
    private function wordmark(): array
    {
        [$svg, $width, $height] = BrandImages::wordmark($this->site->name);

        return [['wordmark.svg' => $svg], ['file' => 'wordmark.svg', 'width' => $width, 'height' => $height, 'uploaded' => false]];
    }

    /**
     * @param array<string, string> $files
     */
    private function replace(BrandAsset $asset, array $files): void
    {
        foreach (self::FILES[$asset->value] as $old) {
            if (!isset($files[$old]) && $this->storage->fileExists(self::FOLDER . $old)) {
                $this->storage->delete(self::FOLDER . $old);
            }
        }
        foreach ($files as $name => $contents) {
            $this->storage->write(self::FOLDER . $name, $contents);
        }
    }

    private function save(BrandingState $state): BrandingState
    {
        $state = $state->withVersion(bin2hex(random_bytes(6)));
        $this->settings->set(BrandingState::SETTING, $state->toJson());

        return $state;
    }
}
