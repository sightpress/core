<?php

declare(strict_types=1);

namespace SightPress\Core\Http\Session;

use DateTimeImmutable;
use FilesystemIterator;
use JsonException;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Keeps each session as a JSON file readable only by the server (`session.store` = `files`, folder `session.path`).
 * For one web server only: a second server can't see the files unless they share the folder. File names are
 * hashes, so session ids never appear on disk. A session expires after the idle timeout; expired files are removed
 * now and then while writing, and by `schedule:run`.
 */
final readonly class FileSessionStore implements ExpiringSessionStore
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

        // Written beside it and moved into place: a request reading at this moment gets the old session or the new
        // one, never a half-written file, which would look like no session and sign the person out.
        $file = $this->file($id);
        $temporary = $file . '.' . bin2hex(random_bytes(8));
        file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR));
        chmod($temporary, 0o600);
        touch($temporary, $this->clock->now()->getTimestamp());
        rename($temporary, $file);

        if (random_int(1, $this->gcProbability) === 1) {
            $this->forgetExpired($this->clock->now());
        }
    }

    public function touch(string $id): void
    {
        $file = $this->file($id);
        if (is_file($file) && !$this->expired($file)) {
            touch($file, $this->clock->now()->getTimestamp());
        }
    }

    public function delete(string $id): void
    {
        $file = $this->file($id);
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function forgetExpired(DateTimeImmutable $now): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $removed = 0;
        foreach (new FilesystemIterator($this->directory) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $this->expired($file->getPathname(), $now)) {
                unlink($file->getPathname());
                ++$removed;
            }
        }

        return $removed;
    }

    private function expired(string $file, ?DateTimeImmutable $now = null): bool
    {
        clearstatcache(true, $file);

        return (int) filemtime($file) + $this->idleTimeout < ($now ?? $this->clock->now())->getTimestamp();
    }

    private function file(string $id): string
    {
        return rtrim($this->directory, '/') . '/' . hash('sha256', $id);
    }
}
