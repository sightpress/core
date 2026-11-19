<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Contract\MediaPictures;

/**
 * Reads a picture of the library from storage: the widest of its copies that is at most the width asked for, or the
 * original when no copy is that small.
 */
final readonly class MediaPictureReader implements MediaPictures
{
    /** The types an AI service reads. */
    private const array TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private MediaRepository $media,
        private MediaFiles $files,
    ) {
    }

    public function picture(int $id, int $width): ?array
    {
        $media = $this->media->find($id);
        if ($media === null || $media->kind() !== MediaKind::Image || !in_array($media->mimeType(), self::TYPES, true)) {
            return null;
        }
        $fits = array_filter($media->variantWidths(), static fn (int $variant): bool => $variant <= $width);
        $chosen = ($media->width() ?? PHP_INT_MAX) <= $width || $fits === [] ? null : max($fits);
        $stream = $this->files->readStream($media, $chosen);
        if ($stream === null) {
            return null;
        }
        $data = (string) stream_get_contents($stream);
        fclose($stream);

        return $data === '' ? null : ['type' => $media->mimeType(), 'data' => $data];
    }
}
