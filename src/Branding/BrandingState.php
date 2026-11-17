<?php

declare(strict_types=1);

namespace SightPress\Core\Branding;

/**
 * What a site's branding is made of, as kept in the site setting `branding.state`: for each picture, whether it was
 * uploaded (or generated), its file under `branding/` and its size; the alt text of the logo and share image; and a
 * version that changes with every change, which addresses carry so browsers and social networks fetch new files.
 */
final readonly class BrandingState
{
    public const string SETTING = 'branding.state';

    /**
     * @param array<string, array{file: string, width: int, height: int, uploaded: bool}> $assets   by
     *                                                                                             {@see BrandAsset}
     *                                                                                             value
     * @param array<string, string>                                                        $alt      by asset value
     *                                                                                             (`logo`), or
     *                                                                                             asset and
     *                                                                                             locale
     *                                                                                             (`logo@pt-PT`)
     */
    public function __construct(
        public array $assets = [],
        public array $alt = [],
        public string $version = '',
    ) {
    }

    public static function fromJson(?string $json): self
    {
        $data = $json === null ? null : json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }
        $stored = is_array($data['assets'] ?? null) ? $data['assets'] : [];
        $assets = [];
        foreach (BrandAsset::cases() as $asset) {
            $entry = $stored[$asset->value] ?? null;
            if (is_array($entry) && is_string($entry['file'] ?? null) && is_int($entry['width'] ?? null) && is_int($entry['height'] ?? null)) {
                $assets[$asset->value] = ['file' => $entry['file'], 'width' => $entry['width'], 'height' => $entry['height'], 'uploaded' => ($entry['uploaded'] ?? false) === true];
            }
        }
        $alt = array_filter(is_array($data['alt'] ?? null) ? $data['alt'] : [], static fn (mixed $text, mixed $key): bool => is_string($text) && is_string($key), ARRAY_FILTER_USE_BOTH);
        /** @var array<string, string> $alt */

        return new self($assets, $alt, is_string($data['version'] ?? null) ? $data['version'] : '');
    }

    public function toJson(): string
    {
        return json_encode(['assets' => $this->assets, 'alt' => $this->alt, 'version' => $this->version], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{file: string, width: int, height: int, uploaded: bool}|null
     */
    public function asset(BrandAsset $asset): ?array
    {
        return $this->assets[$asset->value] ?? null;
    }

    public function isUploaded(BrandAsset $asset): bool
    {
        return ($this->assets[$asset->value]['uploaded'] ?? false) === true;
    }

    /**
     * What the picture shows, in the locale when it has its own text, else in the site's default locale.
     */
    public function alt(BrandAsset $asset, ?string $locale = null): ?string
    {
        return ($locale === null ? null : $this->alt[$asset->value . '@' . $locale] ?? null) ?? $this->alt[$asset->value] ?? null;
    }

    /**
     * The same state with the asset's text in one locale (null: the default text); null or empty text removes it.
     */
    public function withAlt(BrandAsset $asset, ?string $locale, ?string $alt): self
    {
        $alts = $this->alt;
        $key = $locale === null ? $asset->value : $asset->value . '@' . $locale;
        if ($alt === null || $alt === '') {
            unset($alts[$key]);
        } else {
            $alts[$key] = $alt;
        }

        return new self($this->assets, $alts, $this->version);
    }

    /**
     * The same state with the asset (null removes it) and its alt text, and a new version.
     *
     * @param array{file: string, width: int, height: int, uploaded: bool}|null $entry
     */
    public function with(BrandAsset $asset, ?array $entry, ?string $alt = null): self
    {
        $assets = $this->assets;
        $alts = $this->alt;
        if ($entry === null) {
            unset($assets[$asset->value]);
            $alts = array_filter($alts, static fn (string $key): bool => !str_starts_with($key, $asset->value . '@'), ARRAY_FILTER_USE_KEY);
        } else {
            $assets[$asset->value] = $entry;
        }
        if ($alt === null) {
            unset($alts[$asset->value]);
        } else {
            $alts[$asset->value] = $alt;
        }

        return new self($assets, $alts, $this->version);
    }

    public function withVersion(string $version): self
    {
        return new self($this->assets, $this->alt, $version);
    }
}
