<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\SiteRegistryException;
use SightPress\Core\Tenant\SiteRegistryFile;
use SightPress\Core\Tenant\SiteRegistryFormat;
use SightPress\Core\Tenant\SiteRegistryUpgrader;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(SiteRegistryUpgrader::class)]
#[UsesClass(SiteRegistryFile::class)]
#[UsesClass(SiteRegistryFormat::class)]
final class SiteRegistryUpgraderTest extends TestCase
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

    public function testACurrentFileIsLeftAsItIs(): void
    {
        $file = $this->file('<?php return ["version" => 1, "sites" => []];');

        self::assertSame([1, 1], new SiteRegistryUpgrader($file)->upgrade());
        self::assertFileDoesNotExist($file->path . '.bak');
    }

    public function testRunsEveryStepInOrder(): void
    {
        $file = $this->file('<?php return ["version" => 1, "sites" => ["demo" => ["title" => "Demo"]]];');
        $upgrader = new SiteRegistryUpgrader($file, [
            1 => static fn (array $registry): array => ['sites' => ['demo' => ['name' => 'Demo']]] + $registry,
            2 => static fn (array $registry): array => $registry + ['notes' => 'v3'],
        ], 3, static fn (): null => null);

        self::assertSame([1, 3], $upgrader->upgrade());
        self::assertSame(['sites' => ['demo' => ['name' => 'Demo']], 'version' => 3, 'notes' => 'v3'], $file->read());
        self::assertFileExists($file->path . '.bak');
    }

    public function testAnInvalidResultIsNotWritten(): void
    {
        $file = $this->file('<?php return ["version" => 1, "sites" => []];');
        $upgrader = new SiteRegistryUpgrader($file, [1 => static fn (array $registry): array => $registry], 2);

        try {
            $upgrader->upgrade();
            self::fail('The invalid result was written.');
        } catch (SiteRegistryException $exception) {
            self::assertStringContainsString('uses format version 2, made by a newer SightPress', $exception->getMessage());
        }

        self::assertSame(['version' => 1, 'sites' => []], $file->read());
    }

    public function testAMissingStepStopsTheUpgrade(): void
    {
        $file = $this->file('<?php return ["version" => 1];');

        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('There is no upgrade of ' . $file->path . ' from format version 1.');

        new SiteRegistryUpgrader($file, [], 2)->upgrade();
    }

    public function testANewerFileIsNotTouched(): void
    {
        $file = $this->file('<?php return ["version" => 4];');

        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage('uses format version 4, made by a newer SightPress; this version reads up to 1.');

        new SiteRegistryUpgrader($file)->upgrade();
    }

    public function testTheFileMustReturnAnArray(): void
    {
        $file = $this->file('<?php return 1;');

        $this->expectException(SiteRegistryException::class);
        $this->expectExceptionMessage($file->path . ' must return an array.');

        new SiteRegistryUpgrader($file)->upgrade();
    }

    private function file(string $contents): SiteRegistryFile
    {
        return new SiteRegistryFile($this->directory->write('sites.php', $contents));
    }
}
