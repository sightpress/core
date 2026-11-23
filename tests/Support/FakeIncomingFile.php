<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use Closure;
use SightPress\Core\Media\Upload\IncomingFile;

/**
 * A file that has arrived in the bucket, in memory.
 */
final class FakeIncomingFile implements IncomingFile
{
    public bool $deleted = false;

    /**
     * @param (Closure(string, string): void)|null $onCopy what copying does, given the path and the contents
     */
    public function __construct(private readonly string $contents, private readonly ?Closure $onCopy = null)
    {
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function start(int $bytes): string
    {
        return substr($this->contents, 0, $bytes);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }

    public function copyTo(string $path): void
    {
        if ($this->onCopy !== null) {
            ($this->onCopy)($path, $this->contents);
        }
    }

    public function saveTo(string $localFile): void
    {
        file_put_contents($localFile, $this->contents);
    }

    public function delete(): void
    {
        $this->deleted = true;
    }
}
