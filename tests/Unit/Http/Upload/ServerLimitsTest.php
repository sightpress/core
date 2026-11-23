<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Http\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Http\Upload\IniSize;
use SightPress\Core\Http\Upload\ServerLimits;
use SightPress\Core\Tenant\TenantConfig;
use SightPress\Core\Tests\Support\ServerLimitsFixture;

#[CoversClass(IniSize::class)]
#[CoversClass(ServerLimits::class)]
#[UsesNamespace('SightPress\Core\Tenant')]
#[UsesClass(\SightPress\Core\Media\MediaLimits::class)]
final class ServerLimitsTest extends TestCase
{
    private const int MB = ServerLimitsFixture::MB;

    /**
     * @return iterable<string, array{string|false, int|null}>
     */
    public static function iniValues(): iterable
    {
        yield 'bytes' => ['8388608', 8388608];
        yield 'kilobytes' => ['512K', 512 * 1024];
        yield 'megabytes' => ['2M', 2 * self::MB];
        yield 'lower case' => ['64m', 64 * self::MB];
        yield 'gigabytes' => ['1G', 1024 * self::MB];
        yield 'spaces' => [' 8 M', 8 * self::MB];
        yield 'no limit (-1)' => ['-1', null];
        yield 'no limit (0)' => ['0', null];
        yield 'empty' => ['', null];
        yield 'not set' => [false, null];
        yield 'words' => ['lots', null];
    }

    #[DataProvider('iniValues')]
    public function testReadsSizesTheWayPhpDoes(string|false $value, ?int $bytes): void
    {
        self::assertSame($bytes, IniSize::bytes($value));
    }

    public function testWritesSizesTheWayPhpIniDoes(): void
    {
        self::assertSame('65M', IniSize::shorthand(65 * self::MB));
        self::assertSame('65M', IniSize::shorthand(64 * self::MB + 1));
        self::assertSame('1G', IniSize::shorthand(1024 * self::MB));
        self::assertSame('1M', IniSize::shorthand(10));
    }

    public function testTheLargestFileIsTheSmallestLimitLessRoomForTheForm(): void
    {
        self::assertSame(64 * self::MB, ServerLimitsFixture::standard()->requestLimit());
        self::assertSame(2 * self::MB, ServerLimitsFixture::phpDefaults()->requestLimit(), 'upload_max_filesize');
        self::assertSame(7 * self::MB, ServerLimitsFixture::standard(post: 8 * self::MB)->requestLimit(), 'post_max_size');
        self::assertSame(31 * self::MB, ServerLimitsFixture::standard(proxy: 32 * self::MB)->requestLimit(), 'web server');
        self::assertSame(64 * self::MB, ServerLimitsFixture::standard(post: null, proxy: null)->requestLimit(), 'post_max_size 0 = no limit');
        self::assertNull(ServerLimitsFixture::standard(upload: null, post: null, proxy: null)->requestLimit());
        self::assertSame(1, ServerLimitsFixture::standard(post: 1024)->requestLimit(), 'never below one byte');
    }

    public function testCapsAnAppLimit(): void
    {
        self::assertSame(20 * self::MB, ServerLimitsFixture::standard()->cap(20 * self::MB));
        self::assertSame(64 * self::MB, ServerLimitsFixture::standard()->cap(1024 * self::MB));
        self::assertSame(1024 * self::MB, ServerLimitsFixture::standard(upload: null, post: null, proxy: null)->cap(1024 * self::MB));
    }

    public function testKnowsWhenPhpDropsARequest(): void
    {
        self::assertTrue(ServerLimitsFixture::standard()->overflows(65 * self::MB + 1));
        self::assertFalse(ServerLimitsFixture::standard()->overflows(65 * self::MB));
        self::assertFalse(ServerLimitsFixture::standard(post: null)->overflows(PHP_INT_MAX));
    }

    public function testReadsThisPhp(): void
    {
        $limits = ServerLimits::current(TenantConfig::fromArray(['uploads' => ['proxy_body_limit' => '65M']], '/srv'));

        self::assertSame(IniSize::bytes(ini_get('upload_max_filesize')), $limits->uploadMaxFilesize);
        self::assertSame(IniSize::bytes(ini_get('post_max_size')), $limits->postMaxSize);
        self::assertSame(IniSize::bytes(ini_get('memory_limit')), $limits->memoryLimit);
        self::assertSame((int) ini_get('max_file_uploads'), $limits->maxFileUploads);
        self::assertSame((int) ini_get('max_input_vars'), $limits->maxInputVars);
        self::assertSame(65 * self::MB, $limits->proxyBodyLimit);
        self::assertNotSame('', $limits->tempDir);
        self::assertTrue($limits->tempWritable);
        self::assertIsInt($limits->tempFree);
    }
}
