<?php

declare(strict_types=1);

namespace SightPress\Core\Media\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only stream over part of a file: `$length` bytes from `$start`, read in chunks so a large video is never
 * loaded into memory. A stream that can't seek (a file coming from a bucket) is read forward to `$start`, in chunks
 * that are thrown away. It is closed if it can't be positioned.
 */
final class RangeStream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private int $position = 0;

    /**
     * @param resource $resource
     */
    public function __construct($resource, int $start, private readonly int $length)
    {
        $seekable = stream_get_meta_data($resource)['seekable'];
        if (!($seekable ? @fseek($resource, $start) === 0 : self::skip($resource, $start))) {
            fclose($resource);

            throw new RuntimeException('The file can\'t be read from the requested position.');
        }
        $this->resource = $resource;
    }

    /**
     * @param resource $resource
     */
    private static function skip($resource, int $bytes): bool
    {
        while ($bytes > 0) {
            $chunk = @fread($resource, min(65536, $bytes));
            if ($chunk === false || $chunk === '') {
                return false;
            }
            $bytes -= strlen($chunk);
        }

        return true;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;

        return $resource;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->resource === null || $this->position >= $this->length || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('A range stream can\'t seek.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A range stream is read-only.');
    }

    public function isReadable(): bool
    {
        return $this->resource !== null;
    }

    public function read(int $length): string
    {
        $remaining = $this->length - $this->position;
        if ($this->resource === null || $remaining <= 0 || $length <= 0) {
            return '';
        }
        $chunk = fread($this->resource, min($length, $remaining));
        $chunk = is_string($chunk) ? $chunk : '';
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';
        while (($chunk = $this->read(65536)) !== '') {
            $contents .= $chunk;
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = $this->resource === null ? [] : stream_get_meta_data($this->resource);

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
