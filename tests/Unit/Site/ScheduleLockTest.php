<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Site\ScheduleLock;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(ScheduleLock::class)]
final class ScheduleLockTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testOnlyOneRunHoldsTheLockAtATime(): void
    {
        $path = $this->directory->path . '/var/schedule.lock';
        $first = new ScheduleLock($path);
        $second = new ScheduleLock($path);

        self::assertTrue($first->acquire());
        self::assertFileExists($path);
        self::assertFalse($second->acquire());
        $first->release();
        $first->release();
        self::assertTrue($second->acquire());
        $second->release();
    }

    public function testSaysWhenTheLockCantBeMadeOrOpened(): void
    {
        $this->directory->write('var', 'a file, not a folder');
        try {
            new ScheduleLock($this->directory->path . '/var/sub/schedule.lock')->acquire();
            self::fail('A lock in a file was made.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('The folder for the schedule lock', $exception->getMessage());
        }

        mkdir($this->directory->path . '/locks/schedule.lock', 0o775, true);
        $this->expectExceptionMessage('can\'t be opened');
        new ScheduleLock($this->directory->path . '/locks/schedule.lock')->acquire();
    }
}
