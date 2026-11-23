<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Upload;

/**
 * A file that has arrived in the bucket but hasn't been checked yet. Nothing here loads it whole into memory.
 */
interface IncomingFile
{
    public function size(): int;

    /**
     * The first bytes of the file.
     */
    public function start(int $bytes): string;

    /**
     * The file's SHA-256, in hex, from a stream.
     */
    public function sha256(): string;

    /**
     * Copies it to a path in the site's storage. The incoming file stays until {@see delete()}.
     */
    public function copyTo(string $path): void;

    /**
     * Writes it to a local file.
     */
    public function saveTo(string $localFile): void;

    public function delete(): void;
}
