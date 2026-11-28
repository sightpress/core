<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SightPress\Core\Http\Session\FileSessionStore;
use SightPress\Core\Tests\Support\FixedClock;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(FileSessionStore::class)]
final class FileSessionStoreTest extends TestCase
{
    private TemporaryDirectory $directory;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->clock = new FixedClock();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testWritesReadsAndDeletes(): void
    {
        $store = $this->store();

        self::assertNull($store->read('abc'));
        $store->write('abc', ['user' => 1, 'roles' => ['editor']]);

        self::assertSame(['user' => 1, 'roles' => ['editor']], $store->read('abc'));
        $file = $this->directory->path . '/sessions/' . hash('sha256', 'abc');
        self::assertFileExists($file);
        self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        self::assertSame('0700', substr(sprintf('%o', fileperms($this->directory->path . '/sessions')), -4));

        $store->delete('abc');
        $store->delete('abc');
        self::assertNull($store->read('abc'));
    }

    public function testAnIdleSessionExpires(): void
    {
        $store = $this->store();
        $store->write('abc', ['user' => 1]);

        $this->clock->advance(60);
        self::assertSame(['user' => 1], $store->read('abc'));

        $this->clock->advance(61);
        self::assertNull($store->read('abc'));
        self::assertFileDoesNotExist($this->directory->path . '/sessions/' . hash('sha256', 'abc'));
    }

    public function testWritingLeavesOnlyTheFinishedFile(): void
    {
        $store = $this->store();
        $store->write('abc', ['user' => 1]);
        $store->write('abc', ['user' => 2]);

        self::assertSame(['user' => 2], $store->read('abc'));
        $files = glob($this->directory->path . '/sessions/*');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
    }

    public function testTouchingRestartsTheIdleTimeAndNeverBringsASessionBack(): void
    {
        $store = $this->store();
        $store->write('abc', ['user' => 1]);
        $this->clock->advance(100);
        $store->touch('abc');
        $this->clock->advance(100);
        self::assertSame(['user' => 1], $store->read('abc'));

        $store->delete('abc');
        $store->touch('abc');
        self::assertNull($store->read('abc'));
    }

    public function testWritingCleansUpExpiredSessions(): void
    {
        $this->store()->write('old', ['a' => 1]);
        $this->clock->advance(3600);

        $this->store(gcProbability: 1)->write('new', ['b' => 2]);

        self::assertFileDoesNotExist($this->directory->path . '/sessions/' . hash('sha256', 'old'));
        self::assertFileExists($this->directory->path . '/sessions/' . hash('sha256', 'new'));
    }

    public function testTheScheduleRemovesExpiredSessions(): void
    {
        self::assertSame(0, $this->store()->forgetExpired($this->clock->now()), 'nothing to do before the first session');

        $this->store()->write('old', ['a' => 1]);
        $this->clock->advance(100);
        $this->store()->write('new', ['b' => 2]);
        $this->clock->advance(100);

        self::assertSame(1, $this->store()->forgetExpired($this->clock->now()));
        self::assertNull($this->store()->read('old'));
        self::assertSame(['b' => 2], $this->store()->read('new'));
    }

    public function testTouchingAnExpiredSessionLeavesItEnded(): void
    {
        $store = $this->store();
        $store->write('abc', ['user' => 1]);
        $this->clock->advance(200);
        $store->touch('abc');

        self::assertNull($store->read('abc'));
    }

    public function testDamagedFilesAreIgnored(): void
    {
        $this->directory->write('sessions/' . hash('sha256', 'broken'), '{oops');
        $this->directory->write('sessions/' . hash('sha256', 'scalar'), '42');
        touch($this->directory->path . '/sessions/' . hash('sha256', 'broken'), $this->clock->now()->getTimestamp());
        touch($this->directory->path . '/sessions/' . hash('sha256', 'scalar'), $this->clock->now()->getTimestamp());

        self::assertNull($this->store()->read('broken'));
        self::assertNull($this->store()->read('scalar'));
    }

    public function testFailsClearlyWhenTheFolderCantBeCreated(): void
    {
        $this->directory->write('file', 'x');
        $store = new FileSessionStore($this->directory->path . '/file/sessions', $this->clock, 120);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create the session folder');

        @$store->write('abc', []);
    }

    /**
     * @param positive-int $gcProbability
     */
    private function store(int $gcProbability = 1000000): FileSessionStore
    {
        return new FileSessionStore($this->directory->path . '/sessions', $this->clock, 120, $gcProbability);
    }
}
