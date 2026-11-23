<?php

declare(strict_types=1);

namespace SightPress\Core\Infrastructure\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * Copies every file from one storage to another as a stream, and checks each copy's SHA-256 by reading it back. A file
 * already in the target with the same checksum is skipped, so an interrupted run continues where it stopped. The
 * source is never changed here.
 */
final readonly class StorageMigrator
{
    public function migrate(FilesystemOperator $source, FilesystemOperator $target): StorageMigration
    {
        $copied = 0;
        $alreadyThere = 0;
        $verified = [];
        $failed = [];
        /** @var StorageAttributes $item */
        foreach ($source->listContents('', true) as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $path = $item->path();
            try {
                $checksum = self::checksum($source, $path);
                if ($target->fileExists($path) && self::checksum($target, $path) === $checksum) {
                    ++$alreadyThere;
                    $verified[] = $path;
                    continue;
                }
                $stream = $source->readStream($path);
                try {
                    $target->writeStream($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (self::checksum($target, $path) !== $checksum) {
                    $failed[] = $path;
                    continue;
                }
                ++$copied;
                $verified[] = $path;
            } catch (FilesystemException) {
                $failed[] = $path;
            }
        }

        return new StorageMigration($copied, $alreadyThere, $verified, $failed);
    }

    private static function checksum(FilesystemOperator $storage, string $path): string
    {
        $stream = $storage->readStream($path);
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
