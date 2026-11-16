<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Media\MediaTypes;

#[CoversClass(MediaTypes::class)]
#[CoversClass(MediaFormat::class)]
#[CoversClass(MediaKind::class)]
final class MediaTypesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, MediaKind, string}>
     */
    public static function accepted(): iterable
    {
        yield 'jpeg' => ['image/jpeg', 'Photo.JPEG', 'image/jpeg', MediaKind::Image, 'jpg'];
        yield 'png named jpg' => ['image/png', 'photo.jpg', 'image/png', MediaKind::Image, 'png'];
        yield 'webm video' => ['video/webm', 'clip.webm', 'video/webm', MediaKind::Video, 'webm'];
        yield 'm4a spelled by finfo' => ['audio/x-m4a', 'talk.m4a', 'audio/mp4', MediaKind::Audio, 'm4a'];
        yield 'wav' => ['audio/x-wav', 'bell.wav', 'audio/wav', MediaKind::Audio, 'wav'];
        yield 'pdf' => ['application/pdf', 'report.pdf', 'application/pdf', MediaKind::Document, 'pdf'];
        yield 'csv detected as text' => ['text/plain', 'figures.CSV', 'text/csv', MediaKind::Document, 'csv'];
        yield 'text' => ['text/plain', 'notes.md', 'text/plain', MediaKind::Document, 'txt'];
        yield 'captions detected as text' => ['text/plain', 'captions.VTT', 'text/vtt', MediaKind::Document, 'vtt'];
        yield 'captions' => ['text/vtt', 'captions.vtt', 'text/vtt', MediaKind::Document, 'vtt'];
        yield 'upper-case type' => ['Application/ZIP', 'files.zip', 'application/zip', MediaKind::Document, 'zip'];
    }

    #[DataProvider('accepted')]
    public function testMatchesTheDetectedType(string $detected, string $name, string $mime, MediaKind $kind, string $extension): void
    {
        $format = new MediaTypes()->match($detected, $name);

        self::assertNotNull($format);
        self::assertSame([$mime, $kind, $extension], [$format->mimeType, $format->kind, $format->extension]);
    }

    public function testRefusesEverythingElseWhateverTheName(): void
    {
        $types = new MediaTypes();

        foreach (['image/svg+xml' => 'logo.svg', 'text/html' => 'page.jpg', 'application/x-php' => 'shell.png', 'application/octet-stream' => 'a.pdf', '' => 'x.txt'] as $type => $name) {
            self::assertNull($types->match($type, $name), $type);
        }
    }

    public function testSummarisesWhatCanBeUploaded(): void
    {
        self::assertSame(
            'You can upload pictures (JPEG, PNG, WebP, GIF), video (MP4, WebM), audio (MP3, M4A, Ogg, WAV) and documents '
            . '(PDF, Word, Excel, PowerPoint, OpenDocument text, OpenDocument spreadsheet, OpenDocument presentation, plain text, CSV, WebVTT captions, ZIP).',
            new MediaTypes()->summary(),
        );
    }

    public function testListsEveryExtensionForFilePickers(): void
    {
        $accept = new MediaTypes()->accept();

        self::assertStringStartsWith('.jpg,.png,.webp,.gif,.mp4,', $accept);
        self::assertStringContainsString(',.pdf,', $accept);
        self::assertStringNotContainsString('.svg', $accept);
        self::assertSame(array_unique(explode(',', $accept)), explode(',', $accept));
    }

    public function testNamesTheTypesForPeople(): void
    {
        $types = new MediaTypes();

        self::assertSame('PDF', $types->label('application/pdf'));
        self::assertSame('WebVTT captions', $types->label('text/vtt'));
        self::assertNull($types->label('image/svg+xml'));
    }

    public function testKindsKnowWhetherTheyAreInlineAndSpoken(): void
    {
        self::assertTrue(MediaKind::Image->isInline());
        self::assertFalse(MediaKind::Document->isInline());
        self::assertTrue(MediaKind::Video->hasSpeech());
        self::assertTrue(MediaKind::Audio->hasSpeech());
        self::assertFalse(MediaKind::Image->hasSpeech());
    }
}
