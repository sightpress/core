<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Integration\Http\Session;

use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[\PHPUnit\Framework\Attributes\CoversNothing]
final class FileSessionStoreContractTest extends SessionStoreContract
{
    private TemporaryDirectory $directory;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->clock = new FixedClock(new \DateTimeImmutable());
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    protected function store(int $idleTimeout = 3600): SessionStore
    {
        return new FileSessionStore($this->directory->path . '/sessions', $this->clock, $idleTimeout);
    }

    protected function wait(int $seconds): void
    {
        $this->clock->advance($seconds);
    }

    protected function stored(): string
    {
        $stored = '';
        $files = glob($this->directory->path . '/sessions/*');
        foreach ($files === false ? [] : $files as $file) {
            $stored .= basename($file) . "\n" . file_get_contents($file) . "\n";
        }

        return $stored;
    }
}
