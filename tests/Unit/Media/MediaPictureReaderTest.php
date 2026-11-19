<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\MediaPictureReader;
use SightPress\Core\Tests\Support\MediaFixture;

/**
 * Pictures of the library read for an AI service: the widest copy that fits, the original when it is small, nothing
 * for other files or missing ones.
 */
#[CoversClass(MediaPictureReader::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaPictureReaderTest extends TestCase
{
    private MediaFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new MediaFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testReadsTheWidestCopyThatFits(): void
    {
        $reader = new MediaPictureReader($this->fixture->repository(), $this->fixture->files());
        $big = $this->fixture->uploader()->upload($this->fixture->file('big.jpg', MediaFixture::jpeg(1600, 1200)), 'big.jpg', 1)->media;
        $small = $this->fixture->uploader()->upload($this->fixture->file('small.png', MediaFixture::png(200, 100)), 'small.png', 1)->media;

        $picture = $reader->picture((int) $big->id(), 1024);
        self::assertSame('image/jpeg', $picture['type'] ?? null);
        $size = getimagesizefromstring($picture['data'] ?? '');
        self::assertSame(max([0, ...array_filter($big->variantWidths(), static fn (int $width): bool => $width <= 1024)]), $size === false ? null : $size[0]);
        $original = $reader->picture((int) $small->id(), 1024);
        self::assertSame(['image/png', 200], [$original['type'] ?? null, ($s = getimagesizefromstring($original['data'] ?? '')) === false ? null : $s[0]]);
        $tiny = $reader->picture((int) $big->id(), 10);
        self::assertSame(1600, ($s = getimagesizefromstring($tiny['data'] ?? '')) === false ? null : $s[0], 'no copy is small enough: the original');

        self::assertNull($reader->picture(999, 1024));
        $this->fixture->files()->delete($small);
        self::assertNull($reader->picture((int) $small->id(), 1024), 'the file is missing');
    }

    public function testOtherFilesHaveNoPicture(): void
    {
        $reader = new MediaPictureReader($this->fixture->repository(), $this->fixture->files());
        $pdf = $this->fixture->uploader()->upload($this->fixture->file('a.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'a.pdf', 1)->media;

        self::assertNull($reader->picture((int) $pdf->id(), 1024));
    }
}
