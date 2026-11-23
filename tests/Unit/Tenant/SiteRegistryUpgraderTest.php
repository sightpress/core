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
use SightPress\Core\Tests\Support\SiteFixture;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(SiteRegistryUpgrader::class)]
#[CoversClass(SiteRegistryFormat::class)]
#[UsesClass(SiteRegistryFile::class)]
#[UsesClass(\SightPress\Core\Infrastructure\Crypto\SecretBox::class)]
#[UsesClass(\SightPress\Core\Tenant\Site::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteMediaLimits::class)]
#[UsesClass(\SightPress\Core\Tenant\SiteStorage::class)]
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
        $file = $this->file('<?php return ["version" => 2, "sites" => []];');

        self::assertSame([2, 2], new SiteRegistryUpgrader($file)->upgrade());
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
        $file = $this->file('<?php return ["version" => 1];');
        $upgrader = new SiteRegistryUpgrader($file, [1 => static fn (array $registry): array => ['sites' => 'none'] + $registry], 2);

        try {
            $upgrader->upgrade();
            self::fail('The invalid result was written.');
        } catch (SiteRegistryException $exception) {
            self::assertStringContainsString('"sites" must be an array', $exception->getMessage());
        }

        self::assertSame(['version' => 1], $file->read());
    }

    public function testVersionOneMovesToVersionTwoAndKeepsItsSites(): void
    {
        $entry = SiteFixture::entry('demo');
        $file = $this->file('<?php return ' . var_export(['version' => 1, 'sites' => ['demo' => $entry]], true) . ';');

        self::assertSame([1, 2], new SiteRegistryUpgrader($file)->upgrade());
        self::assertSame(['version' => 2, 'sites' => ['demo' => $entry]], $file->read());
        self::assertFileExists($file->path . '.bak');
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
        $this->expectExceptionMessage('uses format version 4, made by a newer SightPress; this version reads up to 2.');

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
