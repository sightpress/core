<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\ImageProcessor;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaLimits;
use SightPress\Core\Media\ProcessedImage;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(ImageProcessor::class)]
#[CoversClass(ProcessedImage::class)]
#[UsesClass(MediaFormat::class)]
#[UsesClass(MediaLimits::class)]
#[UsesNamespace('SightPress\Core')]
final class ImageProcessorTest extends TestCase
{
    private MediaFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new MediaFixture(new MediaLimits(imagePixels: 4_000_000));
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testReEncodesWithoutMetadataAndMakesNarrowerCopies(): void
    {
        $file = $this->fixture->file('photo.jpg', MediaFixture::jpeg(1000, 500, exif: true));
        self::assertStringContainsString('SightPressCam', (string) file_get_contents($file));

        $image = $this->fixture->images()->process($file, self::format('image/jpeg', 'jpg'));

        self::assertSame([1000, 500], [$image->width, $image->height]);
        self::assertStringStartsWith("\xFF\xD8", $image->contents);
        self::assertStringNotContainsString('SightPressCam', $image->contents);
        self::assertStringNotContainsString("Exif\0\0", $image->contents);
        self::assertSame([320, 640, 960], array_keys($image->variants));
        self::assertSame([320, 160], array_slice((array) getimagesizefromstring($image->variants[320]), 0, 2));
        self::assertSame([960, 480], array_slice((array) getimagesizefromstring($image->variants[960]), 0, 2));
    }

    public function testTurnsPhotosTheRightWayUp(): void
    {
        $file = $this->fixture->file('portrait.jpg', MediaFixture::jpeg(400, 200, exif: true, orientation: 6));

        $image = $this->fixture->images()->process($file, self::format('image/jpeg', 'jpg'));

        self::assertSame([200, 400], [$image->width, $image->height]);
        self::assertSame([], $image->variants);
    }

    public function testKeepsTheFormat(): void
    {
        $cases = [
            ['image/png', 'png', MediaFixture::png(700, 100), 'image/png'],
            ['image/webp', 'webp', MediaFixture::webp(50, 50), 'image/webp'],
            ['image/gif', 'gif', MediaFixture::gif(30, 30), 'image/gif'],
        ];
        foreach ($cases as [$mime, $extension, $contents, $expected]) {
            $image = $this->fixture->images()->process($this->fixture->file('picture.' . $extension, $contents), self::format($mime, $extension));

            self::assertSame($expected, getimagesizefromstring($image->contents)['mime'] ?? null);
        }
        self::assertSame([320, 640], array_keys($this->fixture->images()->process($this->fixture->file('wide.png', MediaFixture::png(700, 100)), self::format('image/png', 'png'))->variants));
    }

    public function testRefusesPicturesWithTooManyPixelsBeforeDecodingThem(): void
    {
        $file = $this->fixture->file('huge.png', MediaFixture::png(2001, 2000));

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('This picture is 2001 × 2000 pixels, which is more than 4 million pixels. Make it smaller and upload it again.');

        $this->fixture->images()->process($file, self::format('image/png', 'png'));
    }

    public function testRefusesFilesThatAreNotPictures(): void
    {
        $file = $this->fixture->file('fake.jpg', 'not a picture');

        $this->expectExceptionMessage('This picture can\'t be read. It may be damaged; try saving it again and uploading the new file.');

        $this->fixture->images()->process($file, self::format('image/jpeg', 'jpg'));
    }

    public function testRefusesPicturesWithAValidHeaderButDamagedData(): void
    {
        $file = $this->fixture->file('broken.png', substr(MediaFixture::png(100, 100), 0, 40));

        $this->expectExceptionMessage('This picture can\'t be read.');

        $this->fixture->images()->process($file, self::format('image/png', 'png'));
    }

    /**
     * @param non-empty-string $mime
     * @param non-empty-string $extension
     */
    private static function format(string $mime, string $extension): MediaFormat
    {
        return new MediaFormat($mime, MediaKind::Image, $extension, $extension);
    }
}
