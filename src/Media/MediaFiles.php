<?php

declare(strict_types=1);

namespace SightPress\Core\Media;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use SightPress\Core\Media\Contract\MediaFile;

/**
 * The stored files of the media library, in the site's storage under `media/`: the original as
 * `media/<first two characters of the key>/<key>.<ext>` and each smaller copy of a picture as `<key>-<width>.<ext>`
 * next to it. Random names mean nothing about a file can be guessed from its path.
 */
final readonly class MediaFiles
{
    public function __construct(private FilesystemOperator $storage)
    {
    }

    /**
     * Path of the original (`$width` null) or of one smaller copy, inside the site's storage.
     */
    public static function path(string $key, string $extension, ?int $width = null): string
    {
        return sprintf('media/%s/%s%s.%s', substr($key, 0, 2), $key, $width === null ? '' : '-' . $width, $extension);
    }

    public function write(string $path, string $contents): void
    {
        $this->storage->write($path, $contents);
    }

    /**
     * Copies a local file into storage without reading it all into memory.
     */
    public function writeFile(string $path, string $localFile): void
    {
        $stream = fopen($localFile, 'rb');
        if ($stream === false) {
            throw new MediaException('The uploaded file can\'t be read. Try uploading it again.');
        }
        try {
            $this->storage->writeStream($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Opens a stored file for reading, or null when it is missing.
     *
     * @return resource|null
     */
    public function readStream(MediaFile $file, ?int $width = null)
    {
        try {
            return $this->storage->readStream(self::path($file->key(), self::extension($file), $width));
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * Size in bytes of a stored file, or null when it is missing.
     */
    public function size(MediaFile $file, ?int $width = null): ?int
    {
        try {
            return $this->storage->fileSize(self::path($file->key(), self::extension($file), $width));
        } catch (FilesystemException) {
            return null;
        }
    }

    /**
     * Removes the original and every smaller copy; files that are already gone are skipped.
     */
    public function delete(MediaFile $file): void
    {
        $this->deletePaths(array_map(
            static fn (?int $width): string => self::path($file->key(), self::extension($file), $width),
            [null, ...$file->variantWidths()],
        ));
    }

    /**
     * Removes stored files by path; files that are already gone are skipped.
     *
     * @param list<string> $paths
     */
    public function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            $this->storage->delete($path);
        }
    }

    private static function extension(MediaFile $file): string
    {
        return pathinfo($file->fileName(), PATHINFO_EXTENSION);
    }
}
