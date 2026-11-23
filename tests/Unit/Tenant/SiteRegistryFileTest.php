<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(SiteRegistryFile::class)]
final class SiteRegistryFileTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        chmod($this->directory->path, 0o777);
        $this->directory->remove();
    }

    public function testAMissingFileIsAnEmptyRegistry(): void
    {
        self::assertSame(['version' => 2, 'sites' => []], $this->file()->read());
    }

    public function testReadsWhatTheFileReturns(): void
    {
        $this->directory->write('sites.php', '<?php return ["version" => 7];');

        self::assertSame(['version' => 7], $this->file()->read());
    }

    public function testWritesAPhpFileWithTheNewArrayAndKeepsABackup(): void
    {
        $this->directory->write('sites.php', '<?php return ["version" => 2, "sites" => []];');
        chmod($this->directory->path . '/sites.php', 0o640);
        $file = $this->file();

        $file->update(static function (mixed $current): array {
            self::assertSame(['version' => 2, 'sites' => []], $current);

            return ['version' => 2, 'sites' => ['demo' => ['name' => "It's <b>"]]];
        });

        self::assertSame(['version' => 2, 'sites' => ['demo' => ['name' => "It's <b>"]]], $file->read());
        $contents = (string) file_get_contents($file->path);
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $contents);
        self::assertStringContainsString("*/\n\nreturn array (", $contents);
        self::assertSame(
            '<?php return ["version" => 2, "sites" => []];',
            file_get_contents($file->path . '.bak'),
        );
        self::assertSame(0o640, fileperms($file->path) & 0o777);
        self::assertSame([], glob($this->directory->path . '/.*.tmp'));
    }

    public function testCreatesTheFileWithoutABackup(): void
    {
        $file = $this->file();

        $file->update(static fn (): array => ['version' => 2, 'sites' => []]);

        self::assertSame(['version' => 2, 'sites' => []], $file->read());
        self::assertFileDoesNotExist($file->path . '.bak');
    }

    public function testNullLeavesTheFileAsItIs(): void
    {
        $file = $this->file();

        $file->update(static fn (): ?array => null);

        self::assertFileDoesNotExist($file->path);
    }

    public function testAFolderThatDoesNotExistCannotBeLocked(): void
    {
        $file = new SiteRegistryFile($this->directory->path . '/missing/sites.php');

        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('can\'t be locked: check that its folder exists and is writable.');

        $file->update(static fn (): array => []);
    }

    public function testAReadOnlyFolderCannotBeWritten(): void
    {
        $this->directory->write('sites.php', '<?php return ["version" => 2];');
        $this->directory->write('sites.php.lock', '');
        chmod($this->directory->path, 0o555);
        $file = $this->file();

        try {
            $file->update(static fn (): array => ['version' => 2, 'sites' => []]);
            self::fail('The read-only folder was written.');
        } catch (SiteRegistryException $exception) {
            self::assertStringContainsString('could not be written: check that its folder is writable.', $exception->getMessage());
        }

        self::assertSame(['version' => 2], $file->read());
    }

    private function file(): SiteRegistryFile
    {
        return new SiteRegistryFile($this->directory->path . '/sites.php');
    }
}
