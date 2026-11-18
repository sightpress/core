<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Tenant\LocaleCode;

#[CoversClass(Media::class)]
#[UsesClass(MediaFormat::class)]
#[UsesClass(LocaleCode::class)]
#[UsesClass(MediaKind::class)]
final class MediaTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';

    public function testKeepsWhatWasStored(): void
    {
        $now = new DateTimeImmutable('2026-11-16 10:00:00 UTC');
        $media = self::picture(variants: [640, 320, 640], now: $now);

        self::assertNull($media->id());
        self::assertSame(self::KEY, $media->key());
        self::assertSame(str_repeat('a', 64), $media->checksum());
        self::assertSame(MediaKind::Image, $media->kind());
        self::assertSame('image/jpeg', $media->mimeType());
        self::assertSame('jpg', $media->extension());
        self::assertSame('photo.jpg', $media->fileName());
        self::assertSame('Photo.jpeg', $media->originalName());
        self::assertSame(1234, $media->size());
        self::assertSame([800, 600], [$media->width(), $media->height()]);
        self::assertSame([320, 640], $media->variantWidths());
        self::assertTrue($media->hasVariant(320));
        self::assertFalse($media->hasVariant(960));
        self::assertFalse($media->isPublished());
        self::assertSame(7, $media->uploadedBy());
        self::assertSame($now, $media->createdAt());
        self::assertSame($now, $media->updatedAt());
        self::assertNull($media->altText('en-GB'));
        self::assertNull($media->caption('en-GB'));
        self::assertNull($media->transcript('en-GB'));
    }

    public function testDescriptionsArePerLocaleAndEmptyTextRemovesThem(): void
    {
        $media = self::picture();
        $later = new DateTimeImmutable('2026-11-17 10:00:00 UTC');

        $media->describe('pt-PT', ' Um farol ', null, $later);
        $media->describe('en-GB', 'A lighthouse at dusk', 'Taken in Sagres', $later);

        self::assertSame('Um farol', $media->altText('pt-PT'));
        self::assertNull($media->caption('pt-PT'));
        self::assertSame('A lighthouse at dusk', $media->altText('en-GB'));
        self::assertSame('Taken in Sagres', $media->caption('en-GB'));
        self::assertSame($later, $media->updatedAt());

        $media->describe('en-GB', '  ', '', $later);
        self::assertNull($media->altText('en-GB'));
        self::assertNull($media->caption('en-GB'));
        self::assertSame('Um farol', $media->altText('pt-PT'));
    }

    public function testRefusesTooLongTextAndUnknownLocales(): void
    {
        $media = self::picture();
        $now = new DateTimeImmutable();

        foreach ([
            'The alt text must be 1000 characters or fewer.' => static fn () => $media->describe('en-GB', str_repeat('a', 1001), null, $now),
            'The caption must be 2000 characters or fewer.' => static fn () => $media->describe('en-GB', null, str_repeat('a', 2001), $now),
            '"english" is not a locale code such as "en-GB".' => static fn () => $media->describe('english', 'A', null, $now),
        ] as $message => $change) {
            try {
                $change();
                self::fail($message);
            } catch (MediaException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testOnlyVideoAndAudioHaveTranscripts(): void
    {
        $now = new DateTimeImmutable();
        $audio = new Media(self::KEY, str_repeat('b', 64), new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'), 'talk.mp3', 'Talk.mp3', 10, null, null, [], null, $now);

        $audio->setTranscript('en-GB', ' Hello and welcome. ', $now);
        self::assertSame('Hello and welcome.', $audio->transcript('en-GB'));
        self::assertNull($audio->transcript('pt-PT'));
        $audio->setTranscript('pt-PT', 'Olá e bem-vindos.', $now);
        self::assertSame(['Hello and welcome.', 'Olá e bem-vindos.'], [$audio->transcript('en-GB'), $audio->ownTranscript('pt-PT')]);
        $audio->setTranscript('en-GB', null, $now);
        self::assertNull($audio->transcript('en-GB'));

        $this->expectExceptionMessage('Only video and audio files have a transcript.');
        self::picture()->setTranscript('en-GB', 'Words', $now);
    }

    public function testATranscriptHasALimit(): void
    {
        $audio = new Media(self::KEY, str_repeat('b', 64), new MediaFormat('video/mp4', MediaKind::Video, 'mp4', 'MP4'), 'a.mp4', 'a.mp4', 10, null, null, [], null, new DateTimeImmutable());

        $this->expectExceptionMessage('The transcript must be 200000 characters or fewer.');
        $audio->setTranscript('en-GB', str_repeat('a', 200_001), new DateTimeImmutable());
    }

    public function testRefusesATranscriptInAnUnknownLocale(): void
    {
        $audio = new Media(self::KEY, str_repeat('b', 64), new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'), 'a.mp3', 'a.mp3', 10, null, null, [], null, new DateTimeImmutable());

        $this->expectExceptionMessage('"english" is not a locale code such as "en-GB".');
        $audio->setTranscript('english', 'Words', new DateTimeImmutable());
    }

    public function testATranscriptFromBeforeLanguagesCountsUntilTheMainLanguageGetsOne(): void
    {
        $now = new DateTimeImmutable();
        $audio = new Media(self::KEY, str_repeat('b', 64), new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'), 'a.mp3', 'a.mp3', 10, null, null, [], null, $now);
        new \ReflectionProperty(Media::class, 'transcript')->setValue($audio, 'Old words.');

        self::assertSame(['Old words.', 'Old words.', null], [$audio->transcript('en-GB'), $audio->transcript('pt-PT'), $audio->ownTranscript('en-GB')]);
        $audio->setTranscript('pt-PT', 'Palavras.', $now);
        self::assertSame(['Old words.', 'Palavras.'], [$audio->transcript('en-GB'), $audio->transcript('pt-PT')]);
        $audio->setTranscript('en-GB', 'New words.', $now, main: true);
        self::assertSame(['New words.', 'Palavras.', null], [$audio->transcript('en-GB'), $audio->transcript('pt-PT'), $audio->transcript('es')]);
    }

    public function testPublishesAndUnpublishes(): void
    {
        $media = self::picture();
        $later = new DateTimeImmutable('2026-11-18 10:00:00 UTC');

        $media->publish($later);
        self::assertTrue($media->isPublished());
        self::assertSame($later, $media->updatedAt());
        $media->unpublish($later);
        self::assertFalse($media->isPublished());
    }

    public function testRefusesBadKeysChecksumsAndSizes(): void
    {
        foreach ([
            'A media key must be 32 lower-case hex characters.' => ['ABC', str_repeat('a', 64), 1, 1],
            'A media checksum must be a SHA-256 hash in lower-case hex.' => [self::KEY, 'md5', 1, 1],
            'A picture needs both a width and a height of at least 1 pixel.' => [self::KEY, str_repeat('a', 64), 1, null],
            'A picture needs both a width and a height of at least 1 pixel. ' => [self::KEY, str_repeat('a', 64), 0, 5],
            'A picture needs both a width and a height of at least 1 pixel.  ' => [self::KEY, str_repeat('a', 64), 5, 0],
        ] as $message => [$key, $checksum, $width, $height]) {
            try {
                new Media($key, $checksum, new MediaFormat('image/png', MediaKind::Image, 'png', 'PNG'), 'a.png', 'a.png', 1, $width, $height, [], null, new DateTimeImmutable());
                self::fail($message);
            } catch (MediaException $exception) {
                self::assertSame(trim($message), $exception->getMessage());
            }
        }
    }

    /**
     * @param list<int> $variants
     */
    private static function picture(array $variants = [], ?DateTimeImmutable $now = null): Media
    {
        return new Media(
            self::KEY,
            str_repeat('a', 64),
            new MediaFormat('image/jpeg', MediaKind::Image, 'jpg', 'JPEG'),
            'photo.jpg',
            'Photo.jpeg',
            1234,
            800,
            600,
            $variants,
            7,
            $now ?? new DateTimeImmutable('2026-11-16 10:00:00 UTC'),
        );
    }
}
