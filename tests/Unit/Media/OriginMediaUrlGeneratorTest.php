<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\OriginMediaUrlGenerator;

#[CoversClass(OriginMediaUrlGenerator::class)]
#[UsesClass(Media::class)]
#[UsesClass(MediaFormat::class)]
final class OriginMediaUrlGeneratorTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';

    public function testAddressesTheOriginalAndEachSmallerCopy(): void
    {
        $urls = new OriginMediaUrlGenerator();
        $photo = self::picture();

        self::assertSame('/media/' . self::KEY . '/harbour-at-dawn.jpg', $urls->url($photo));
        self::assertSame('/media/' . self::KEY . '/640w/harbour-at-dawn.jpg', $urls->url($photo, 640));
        self::assertSame('/media/' . self::KEY . '/harbour-at-dawn.jpg', $urls->url($photo, 500), 'no copy that wide: the original');
    }

    public function testListsEveryWidthOfAPictureForSrcset(): void
    {
        self::assertSame(
            '/media/' . self::KEY . '/320w/harbour-at-dawn.jpg 320w, /media/' . self::KEY . '/640w/harbour-at-dawn.jpg 640w, '
                . '/media/' . self::KEY . '/harbour-at-dawn.jpg 800w',
            new OriginMediaUrlGenerator()->srcset(self::picture()),
        );
    }

    public function testOtherFilesHaveNoSrcset(): void
    {
        $pdf = new Media(self::KEY, str_repeat('a', 64), new MediaFormat('application/pdf', MediaKind::Document, 'pdf', 'PDF'), 'report.pdf', 'Report.pdf', 10, null, null, [], null, new DateTimeImmutable());

        self::assertSame('', new OriginMediaUrlGenerator()->srcset($pdf));
        self::assertSame('/media/' . self::KEY . '/report.pdf', new OriginMediaUrlGenerator()->url($pdf, 320));
    }

    private static function picture(): Media
    {
        return new Media(self::KEY, str_repeat('a', 64), new MediaFormat('image/jpeg', MediaKind::Image, 'jpg', 'JPEG'), 'harbour-at-dawn.jpg', 'Harbour at dawn.jpg', 10, 800, 600, [640, 320], null, new DateTimeImmutable());
    }
}
