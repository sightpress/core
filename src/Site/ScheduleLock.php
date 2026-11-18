<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use RuntimeException;

/**
 * Keeps two `schedule:run` of one installation from running at once: a lock on a file in the project's `var/`
 * folder, which the operating system lets go of when the process ends, even if it crashed.
 */
final class ScheduleLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return bool false when another run holds the lock
     *
     * @throws RuntimeException when the lock file can't be opened
     */
    public function acquire(): bool
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('The folder for the schedule lock (%s) can\'t be made.', $directory));
        }
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException(sprintf('The schedule lock (%s) can\'t be opened.', $this->path));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
