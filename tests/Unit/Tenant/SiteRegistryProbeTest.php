<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Tenant;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Tenant\SiteRegistryProbe;
use SightPress\Core\Tests\Support\TemporaryDirectory;

#[CoversClass(SiteRegistryProbe::class)]
final class SiteRegistryProbeTest extends TestCase
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

    public function testAMissingRegistryHasNoSites(): void
    {
        self::assertFalse(new SiteRegistryProbe($this->directory->path . '/sites.php')->hasSites());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function registries(): iterable
    {
        yield 'not an array' => ['<?php return 1;', false];
        yield 'no sites key' => ['<?php return ["version" => 1];', false];
        yield 'empty' => ['<?php return ["version" => 1, "sites" => []];', false];
        yield 'one site' => ['<?php return ["version" => 1, "sites" => [["slug" => "demo"]]];', true];
    }

    #[DataProvider('registries')]
    public function testItCountsTheSites(string $contents, bool $expected): void
    {
        $file = $this->directory->write('sites.php', $contents);

        self::assertSame($expected, new SiteRegistryProbe($file)->hasSites());
    }
}
