<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Editor;

use SightPress\Core\Tests\Support\Text;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\BlockSchema;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaRepository;
use SightPress\Core\Media\MediaTypes;
use SightPress\Core\Page\Admin\Editor\MediaChoices;
use SightPress\Core\Tests\Support\CoreSiteDatabase;
use SightPress\Core\Tests\Support\CountingMediaLibrary;

#[CoversClass(MediaChoices::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaChoicesTest extends TestCase
{
    public function testOffersTheFilesEachMediaFieldCanUse(): void
    {
        $repository = new MediaRepository(new CoreSiteDatabase()->entityManager);
        /** @var array<string, int> $ids */
        $ids = [];
        foreach ([
            ['photo.png', 'image/png', MediaKind::Image, 'png', true],
            ['captions.vtt', 'text/vtt', MediaKind::Document, 'vtt', true],
            ['report.pdf', 'application/pdf', MediaKind::Document, 'pdf', false],
            ['talk.mp3', 'audio/mpeg', MediaKind::Audio, 'mp3', true],
            ['clip.mp4', 'video/mp4', MediaKind::Video, 'mp4', true],
        ] as $index => [$name, $mime, $kind, $extension, $published]) {
            $media = new Media(str_repeat(dechex($index + 1), 32), str_repeat(dechex($index + 1), 64), new MediaFormat($mime, $kind, $extension, $extension), 'f', $name, 1, null, null, [], null, new DateTimeImmutable("2026-11-16 1{$index}:00:00 UTC"));
            if ($published) {
                $media->publish(new DateTimeImmutable('2026-11-16 12:00:00 UTC'));
            }
            $repository->save($media);
            $ids[$name] = (int) $media->id();
        }
        $library = new CountingMediaLibrary($repository);
        $choices = new MediaChoices($library, new MediaTypes());

        self::assertSame([$ids['photo.png'] => 'photo.png (PNG)'], Text::of($choices->for(BlockSchema::FORMAT_IMAGE)));
        self::assertSame([$ids['captions.vtt'] => 'captions.vtt (WebVTT captions)'], Text::of($choices->for(BlockSchema::FORMAT_CAPTIONS)));
        self::assertSame([$ids['report.pdf'] => 'report.pdf (PDF), not published yet', $ids['captions.vtt'] => 'captions.vtt (WebVTT captions)'], Text::of($choices->for(BlockSchema::FORMAT_DOCUMENT)));
        self::assertSame([$ids['talk.mp3']], Text::of(array_keys($choices->for(BlockSchema::FORMAT_AUDIO))));
        self::assertSame([$ids['clip.mp4']], Text::of(array_keys($choices->for(BlockSchema::FORMAT_VIDEO))));
        self::assertCount(5, $choices->for(BlockSchema::FORMAT_MEDIA));
        self::assertSame([], Text::of($library->calls), 'lists come from choices(), not one file at a time');

        self::assertSame('photo.png (PNG)', Text::of($choices->for(BlockSchema::FORMAT_VIDEO, $ids['photo.png'])[$ids['photo.png']]), 'the chosen file is kept');
        self::assertSame('File 999 (no longer in the media library)', Text::of($choices->for(BlockSchema::FORMAT_IMAGE, 999)[999]));
        self::assertSame([$ids['photo.png'] => 'photo.png (PNG)'], Text::of($choices->for(BlockSchema::FORMAT_IMAGE, $ids['photo.png'])));
    }
}
