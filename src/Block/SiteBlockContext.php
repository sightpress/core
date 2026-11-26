<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use NumberFormatter;
use Psr\Http\Message\ServerRequestInterface;
use SightPress\Core\Block\Contract\HttpBlockContext;
use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaFormats;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Media\Contract\MediaUrlGenerator;

/**
 * Where blocks are rendered on a site: the locale, the site's address and its media library. Core's own block
 * types also use it to render the blocks inside columns, to get a picture's sizes and a file's type and size; files
 * are looked up once for the whole page ({@see preload()}). When the page is rendered for a visitor it carries the
 * request, for blocks with forms.
 */
final class SiteBlockContext implements HttpBlockContext
{
    /** @var array<int, MediaFile|null> */
    private array $files = [];

    /**
     * @param non-empty-string $locale
     * @param string           $baseUrl      scheme and host, without a trailing slash
     * @param string           $localePrefix `''` for the site's default locale, otherwise for example `/pt-br`
     */
    public function __construct(
        private readonly string $locale,
        private readonly string $baseUrl,
        private readonly string $localePrefix,
        private readonly MediaLibrary $media,
        private readonly MediaUrlGenerator $urls,
        private readonly DefaultBlockRenderer $renderer,
        private readonly MediaFormats $formats,
        private readonly ?ServerRequestInterface $request = null,
    ) {
    }

    public function request(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function absoluteUrl(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        if ($path === '/' && $this->localePrefix !== '') {
            return $this->baseUrl . $this->localePrefix;
        }

        return $this->baseUrl . $this->localePrefix . $path;
    }

    /**
     * A link from content as an absolute URL: addresses on the site (`/about`) get the site's scheme and host;
     * `https:`, `mailto:`, `tel:` and `#…` links stay as they are.
     */
    public function linkUrl(string $href): string
    {
        return str_starts_with($href, '/') && !str_starts_with($href, '//') ? $this->baseUrl . $href : $href;
    }

    public function mediaUrl(string $mediaId, ?string $variant = null): ?string
    {
        $file = preg_match('/^[1-9][0-9]{0,18}$/', $mediaId) === 1 ? $this->file((int) $mediaId) : null;
        if ($file === null) {
            return null;
        }
        $width = $variant !== null && preg_match('/^([1-9][0-9]{0,4})w$/', $variant, $match) === 1 ? (int) $match[1] : null;
        $url = $this->urls->url($file, $width);
        $url = str_starts_with($url, '/') ? $this->baseUrl . $url : $url;

        return $url === '' ? null : $url;
    }

    /**
     * The address of a captions file on the page's own origin, which is the only place a `<track>` loads from without
     * CORS, also when the site's other files are on a CDN or the page is on another of the site's domains.
     */
    public function captionsUrl(MediaFile $file): string
    {
        return $this->urls->siteUrl($file);
    }

    /**
     * A published picture, with what an `<img>` needs.
     *
     * @return array{src: string, srcset: string, width: ?int, height: ?int}|null
     */
    public function image(int $id): ?array
    {
        $file = $this->file($id);
        if ($file === null || $file->kind() !== MediaKind::Image) {
            return null;
        }

        return ['src' => $this->urls->url($file), 'srcset' => $this->urls->srcset($file), 'width' => $file->width(), 'height' => $file->height()];
    }

    /**
     * Type and size of a file for people, in the content's locale: "PDF, 2.3 MB", "Word, 840 KB".
     */
    public function fileDetails(MediaFile $file): string
    {
        $label = $this->formats->label($file->mimeType()) ?? strtoupper(pathinfo($file->fileName(), PATHINFO_EXTENSION));
        [$value, $unit] = [(float) $file->size(), 'B'];
        foreach (['KB', 'MB', 'GB'] as $next) {
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit = $next;
        }
        $number = new NumberFormatter($this->locale, NumberFormatter::DECIMAL);
        $number->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $unit !== 'B' && $value < 10 ? 1 : 0);

        return $label . ', ' . $number->format($value) . ' ' . $unit;
    }

    /**
     * Looks up the published files with these ids in one go.
     *
     * @param list<int> $ids
     */
    public function preload(array $ids): void
    {
        $ids = array_values(array_diff(array_unique($ids), array_keys($this->files)));
        if ($ids === []) {
            return;
        }
        $found = $this->media->files($ids);
        foreach ($ids as $id) {
            $this->files[$id] = isset($found[$id]) && $found[$id]->isPublished() ? $found[$id] : null;
        }
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks
     */
    public function renderHtml(array $blocks): string
    {
        return $this->renderer->html($blocks, $this);
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>, styles?: array<string, string>}> $blocks
     */
    public function renderMarkdown(array $blocks): string
    {
        return $this->renderer->markdown($blocks, $this);
    }

    /**
     * A published file of the media library, or null.
     */
    public function file(int $id): ?MediaFile
    {
        $this->preload([$id]);

        return $this->files[$id];
    }
}
