<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use FilesystemIterator;
use JsonException;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Keeps each session as a JSON file readable only by the server. File names are hashes, so session ids never appear
 * on disk. A session expires after the idle timeout; expired files are removed now and then while writing.
 */
final readonly class FileSessionStore implements SessionStore
{
    /**
     * @param positive-int $idleTimeout   seconds without a request before a session ends
     * @param positive-int $gcProbability clean up expired files on 1 write in this many
     */
    public function __construct(
        private string $directory,
        private ClockInterface $clock,
        private int $idleTimeout,
        private int $gcProbability = 100,
    ) {
    }

    public function read(string $id): ?array
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }
        if ($this->expired($file)) {
            unlink($file);

            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function write(string $id, array $data): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Could not create the session folder %s.', $this->directory));
        }

        $file = $this->file($id);
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($file, 0o600);
        touch($file, $this->clock->now()->getTimestamp());

        if (random_int(1, $this->gcProbability) === 1) {
            $this->collectGarbage();
        }
    }

    public function delete(string $id): void
    {
        $file = $this->file($id);
        if (is_file($file)) {
            unlink($file);
        }
    }

    private function collectGarbage(): void
    {
        foreach (new FilesystemIterator($this->directory) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $this->expired($file->getPathname())) {
                unlink($file->getPathname());
            }
        }
    }

    private function expired(string $file): bool
    {
        clearstatcache(true, $file);

        return (int) filemtime($file) + $this->idleTimeout < $this->clock->now()->getTimestamp();
    }

    private function file(string $id): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $id);
    }
}
