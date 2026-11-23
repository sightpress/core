<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Infrastructure\Storage;

use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Infrastructure\Storage\StorageMigration;
use SightPress\Core\Infrastructure\Storage\StorageMigrator;
use SightPress\Core\Tests\Support\TestProject;

#[CoversClass(StorageMigrator::class)]
#[CoversClass(StorageMigration::class)]
final class StorageMigratorTest extends TestCase
{
    private TestProject $project;

    protected function setUp(): void
    {
        $this->project = new TestProject();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    private function folder(string $name): string
    {
        return $this->project->path() . '/' . $name;
    }

    public function testCopiesVerifiesAndResumes(): void
    {
        $source = new Filesystem(new LocalFilesystemAdapter($this->folder('source')));
        $target = new Filesystem(new LocalFilesystemAdapter($this->folder('target')));
        for ($i = 0; $i < 100; ++$i) {
            $source->write(sprintf('media/%02d/file-%d.bin', $i % 7, $i), str_repeat((string) $i, 50));
        }
        $first = new StorageMigrator()->migrate($source, $target);
        self::assertTrue($first->isComplete());
        self::assertSame(100, $first->copied);
        self::assertSame('5050', substr($target->read('media/01/file-50.bin'), 0, 4));

        $target->write('media/01/file-50.bin', 'damaged');
        $second = new StorageMigrator()->migrate($source, $target);
        self::assertSame(1, $second->copied);
        self::assertSame(99, $second->alreadyThere);
        self::assertCount(100, $second->verified);
        self::assertTrue($source->fileExists('media/01/file-50.bin'));
    }

    public function testReportsAFileThatCannotBeWrittenAndContinues(): void
    {
        $source = new Filesystem(new LocalFilesystemAdapter($this->folder('source')));
        $source->write('a.bin', 'a');
        $source->write('b.bin', 'b');
        $target = new Filesystem(new class ($this->folder('target')) extends LocalFilesystemAdapter {
            public function writeStream(string $path, $contents, Config $config): void
            {
                if ($path === 'a.bin') {
                    throw UnableToWriteFile::atLocation($path, 'refused');
                }
                parent::writeStream($path, $contents, $config);
            }
        });
        $result = new StorageMigrator()->migrate($source, $target);
        self::assertFalse($result->isComplete());
        self::assertSame(['a.bin'], $result->failed);
        self::assertSame(['b.bin'], $result->verified);
    }

    public function testReportsACopyWhoseChecksumDiffers(): void
    {
        $source = new Filesystem(new LocalFilesystemAdapter($this->folder('source')));
        $source->write('a.bin', 'right');
        $target = new Filesystem(new class ($this->folder('target')) extends LocalFilesystemAdapter {
            public function writeStream(string $path, $contents, Config $config): void
            {
                $this->write($path, 'wrong', $config);
            }
        });
        self::assertSame(['a.bin'], new StorageMigrator()->migrate($source, $target)->failed);
    }
}
