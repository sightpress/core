<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Core\Block\Type\VideoBlock;
use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaLibrary;
use SightPress\Core\Tenant\Site;

/**
 * Fills in the texts a block left empty from the media library, in the page's language, or else the main language's
 * (never an empty text when the library has one): the alt text and caption of a picture (image blocks, gallery
 * pictures; not for decorative pictures) the transcript of a video or audio file, and a video's captions file. Text written in the block
 * always wins. Blocks inside other blocks (columns) are filled too. The library is asked once for all the files.
 */
final class MediaTexts
{
    private const int ALT_MAX = 1000;

    private const int CAPTION_MAX = 2000;

    /** @var array<int, MediaFile|null> files looked up so far */
    private array $files = [];

    public function __construct(
        private readonly MediaLibrary $media,
        private readonly Site $site,
    ) {
    }

    /**
     * Looks up the files of many pages in one query, before filling them one by one.
     *
     * @param list<list<array{type: string, version: int, data: array<string, mixed>}>> $pages
     */
    public function preload(array $pages): void
    {
        $this->lookUp(self::ids($pages));
    }

    /**
     * @param list<array{type: string, version: int, data: array<string, mixed>}> $blocks
     *
     * @return list<array{type: string, version: int, data: array<string, mixed>}>
     */
    public function fill(array $blocks, string $locale): array
    {
        $ids = self::ids($blocks);
        if ($ids === []) {
            return $blocks;
        }
        $this->lookUp($ids);

        /** @var list<array{type: string, version: int, data: array<string, mixed>}> */
        return $this->blocks($blocks, array_filter($this->files), $locale);
    }

    /**
     * @param list<int> $ids
     */
    private function lookUp(array $ids): void
    {
        $missing = array_values(array_diff($ids, array_keys($this->files)));
        if ($missing === []) {
            return;
        }
        $found = $this->media->files($missing);
        foreach ($missing as $id) {
            $this->files[$id] = $found[$id] ?? null;
        }
    }

    /**
     * @param array<array-key, mixed> $blocks
     *
     * @return list<int>
     */
    private static function ids(array $blocks): array
    {
        $ids = [];
        array_walk_recursive($blocks, static function (mixed $value, int|string $key) use (&$ids): void {
            if ($key === 'media' && is_int($value)) {
                $ids[$value] = $value;
            }
        });

        return array_values($ids);
    }

    /**
     * @param array<array-key, mixed>  $value
     * @param array<int, MediaFile>    $files
     *
     * @return array<array-key, mixed>
     */
    private function blocks(array $value, array $files, string $locale): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->blocks($item, $files, $locale);
            }
        }
        if (!is_string($value['type'] ?? null) || !is_array($value['data'] ?? null)) {
            return $value;
        }
        $data = $value['data'];
        $value['data'] = match ($value['type']) {
            'image' => $this->picture($data, $files, $locale),
            'gallery' => $this->gallery($data, $files, $locale),
            'video' => $this->captions($this->recording($data, $files, $locale), $files, $locale),
            'audio' => $this->recording($data, $files, $locale),
            default => $data,
        };

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<int, MediaFile>   $files
     *
     * @return array<array-key, mixed>
     */
    private function gallery(array $data, array $files, string $locale): array
    {
        if (is_array($data['images'] ?? null)) {
            $data['images'] = array_map(
                fn (mixed $image): mixed => is_array($image) ? $this->picture($image, $files, $locale) : $image,
                $data['images'],
            );
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<int, MediaFile>   $files
     *
     * @return array<array-key, mixed>
     */
    private function picture(array $data, array $files, string $locale): array
    {
        $file = is_int($data['media'] ?? null) ? ($files[$data['media']] ?? null) : null;
        if ($file === null) {
            return $data;
        }
        if (($data['decorative'] ?? false) !== true) {
            $data = self::withText($data, 'alt', $this->text(static fn (string $in): ?string => $file->altText($in), $locale), self::ALT_MAX);
        }

        return self::withText($data, 'caption', $this->text(static fn (string $in): ?string => $file->caption($in), $locale), self::CAPTION_MAX);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<int, MediaFile>   $files
     *
     * @return array<array-key, mixed>
     */
    private function recording(array $data, array $files, string $locale): array
    {
        $file = is_int($data['media'] ?? null) ? ($files[$data['media']] ?? null) : null;
        if ($file === null) {
            return $data;
        }

        return self::withText($data, 'transcript', $this->text(static fn (string $in): ?string => $file->transcript($in), $locale), VideoBlock::TRANSCRIPT_MAX_LENGTH);
    }

    /**
     * A video block without a captions file of its own gets the library's, in the page's language or else the main
     * language's, unless it has no speech.
     *
     * @param array<array-key, mixed> $data
     * @param array<int, MediaFile>   $files
     *
     * @return array<array-key, mixed>
     */
    private function captions(array $data, array $files, string $locale): array
    {
        $file = is_int($data['media'] ?? null) ? ($files[$data['media']] ?? null) : null;
        if ($file === null || is_int($data['captions'] ?? null) || ($data['no_speech'] ?? false) === true) {
            return $data;
        }
        $tracks = $file->captionFiles();
        $language = isset($tracks[$locale]) ? $locale : $this->site->defaultLocale;
        if (isset($tracks[$language])) {
            $data['captions'] = $tracks[$language];
            $data['captions_language'] = $language;
        }

        return $data;
    }

    /**
     * @param callable(string): ?string $text
     */
    private function text(callable $text, string $locale): ?string
    {
        $found = $text($locale);

        return $found === null || trim($found) === '' ? $text($this->site->defaultLocale) : $found;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function withText(array $data, string $key, ?string $text, int $max): array
    {
        $own = $data[$key] ?? null;
        if ((!is_string($own) || trim($own) === '') && $text !== null && trim($text) !== '' && mb_strlen($text) <= $max) {
            $data[$key] = $text;
        }

        return $data;
    }
}
