<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFiles;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(MediaFiles::class)]
#[UsesClass(Media::class)]
#[UsesClass(MediaFormat::class)]
#[UsesClass(MediaException::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaFilesTest extends TestCase
{
    private const string KEY = 'ab23456789abcdef0123456789abcdef';

    private MediaFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new MediaFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testStoresFilesUnderRandomPaths(): void
    {
        self::assertSame('media/ab/' . self::KEY . '.jpg', MediaFiles::path(self::KEY, 'jpg'));
        self::assertSame('media/ab/' . self::KEY . '-320.jpg', MediaFiles::path(self::KEY, 'jpg', 320));
    }

    public function testWritesReadsAndDeletesTheOriginalAndItsCopies(): void
    {
        $files = $this->fixture->files();
        $media = self::media([320]);
        $files->write(MediaFiles::path(self::KEY, 'jpg'), 'original');
        $files->writeFile(MediaFiles::path(self::KEY, 'jpg', 320), $this->fixture->file('small.jpg', 'small'));

        self::assertSame(8, $files->size($media));
        self::assertSame(5, $files->size($media, 320));
        $stream = $files->readStream($media, 320);
        self::assertIsResource($stream);
        self::assertSame('small', stream_get_contents($stream));
        fclose($stream);

        $files->delete($media);

        self::assertSame([], $this->fixture->stored());
        self::assertNull($files->readStream($media));
        self::assertNull($files->size($media));
        $files->delete($media);
    }

    public function testRefusesAFileItCannotRead(): void
    {
        $this->expectException(MediaException::class);

        @$this->fixture->files()->writeFile('media/x.txt', $this->fixture->directory->path . '/missing.txt');
    }

    /**
     * @param list<int> $variants
     */
    private static function media(array $variants): Media
    {
        return new Media(self::KEY, str_repeat('c', 64), new MediaFormat('image/jpeg', MediaKind::Image, 'jpg', 'JPEG'), 'photo.jpg', 'photo.jpg', 8, 640, 480, $variants, null, new DateTimeImmutable());
    }
}
