<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaFile;
use SightPress\Core\Media\Contract\MediaUrlGenerator;

/**
 * Origin mode: files are served by the site under `/media/<key>/<file name>`, and smaller copies of pictures under
 * `/media/<key>/<width>w/<file name>` ({@see Http\MediaFileHandler}).
 */
final readonly class OriginMediaUrlGenerator implements MediaUrlGenerator
{
    public const string PREFIX = '/media/';

    public function url(MediaFile $file, ?int $width = null): string
    {
        $size = $width !== null && in_array($width, $file->variantWidths(), true) ? $width . 'w/' : '';

        return self::PREFIX . $file->key() . '/' . $size . rawurlencode($file->fileName());
    }

    public function srcset(MediaFile $file): string
    {
        $width = $file->width();
        if ($width === null) {
            return '';
        }

        $candidates = array_map(
            fn (int $variant): string => $this->url($file, $variant) . ' ' . $variant . 'w',
            $file->variantWidths(),
        );
        $candidates[] = $this->url($file) . ' ' . $width . 'w';

        return implode(', ', $candidates);
    }
}
