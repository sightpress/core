<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Media;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Media\Contract\MediaChanged;
use SightPress\Core\Media\Contract\MediaDeleted;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Media\Entity\Media;
use SightPress\Core\Media\MediaEditor;
use SightPress\Core\Media\MediaException;
use SightPress\Core\Media\MediaFormat;
use SightPress\Core\Tests\Support\MediaFixture;

#[CoversClass(MediaEditor::class)]
#[CoversClass(MediaChanged::class)]
#[CoversClass(MediaDeleted::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaEditorTest extends TestCase
{
    private MediaFixture $fixture;

    private MediaEditor $editor;

    protected function setUp(): void
    {
        $this->fixture = new MediaFixture();
        $this->editor = new MediaEditor($this->fixture->repository(), $this->fixture->files(), $this->fixture->clock, $this->fixture);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

    public function testSavesWhatChangedAndSaysSo(): void
    {
        $picture = $this->fixture->uploader()->upload($this->fixture->file('a.png', MediaFixture::png()), 'a.png', 1)->media;
        $this->fixture->events = [];

        $changed = $this->editor->update($picture, [
            'en-GB' => ['alt' => 'A lighthouse at dusk', 'caption' => null],
            'pt-BR' => ['alt' => '', 'caption' => 'Farol'],
        ], null, true, 7);

        self::assertSame(['alt', 'caption', 'published'], $changed);
        self::assertSame(['A lighthouse at dusk', null, 'Farol'], [$picture->altText('en-GB'), $picture->altText('pt-BR'), $picture->caption('pt-BR')]);
        self::assertTrue($picture->isPublished());
        self::assertEquals([new MediaChanged((int) $picture->id(), 7, ['alt', 'caption', 'published'])], $this->fixture->events);

        self::assertSame([], $this->editor->update($picture, ['en-GB' => ['alt' => ' A lighthouse at dusk ', 'caption' => '']], null, true, 7));
        self::assertCount(1, $this->fixture->events, 'nothing changed, nothing said');

        self::assertSame(['published'], $this->editor->update($picture, [], null, false, 7));
        self::assertFalse($picture->isPublished());
    }

    public function testAltTextFromTheAssistantStaysMarkedUntilAPersonChecksOrChangesIt(): void
    {
        $picture = $this->fixture->uploader()->upload($this->fixture->file('a.png', MediaFixture::png()), 'a.png', 1)->media;

        self::assertSame(['alt'], $this->editor->update($picture, ['en-GB' => ['alt' => 'A lighthouse', 'caption' => null, 'alt_suggested' => true]], null, null, 7));
        self::assertTrue($picture->isAltSuggested('en-GB'));
        self::assertSame([], $this->editor->update($picture, ['en-GB' => ['alt' => 'A lighthouse', 'caption' => null]], null, null, 7), 'kept while the text is the same');
        self::assertTrue($picture->isAltSuggested('en-GB'));
        self::assertSame(['alt'], $this->editor->update($picture, ['en-GB' => ['alt' => 'A lighthouse', 'caption' => null, 'alt_suggested' => false]], null, null, 7), 'checked');
        self::assertFalse($picture->isAltSuggested('en-GB'));

        $this->editor->update($picture, ['en-GB' => ['alt' => 'A lighthouse', 'caption' => null, 'alt_suggested' => true]], null, null, 7);
        self::assertSame(['alt'], $this->editor->update($picture, ['en-GB' => ['alt' => 'A white lighthouse', 'caption' => null]], null, null, 7));
        self::assertFalse($picture->isAltSuggested('en-GB'), 'changed by a person');
    }

    public function testVideoAndAudioHaveATranscript(): void
    {
        $audio = new Media(str_repeat('a', 32), str_repeat('b', 64), new MediaFormat('audio/mpeg', MediaKind::Audio, 'mp3', 'MP3'), 'talk.mp3', 'Talk.mp3', 10, null, null, [], 1, new DateTimeImmutable());
        $this->fixture->repository()->save($audio);

        self::assertSame(['transcript'], $this->editor->update($audio, [], ['en-GB' => 'Hello and welcome.', 'pt-PT' => ''], null, 1, 'en-GB'));
        self::assertSame(['Hello and welcome.', 'Hello and welcome.'], [$audio->transcript('en-GB'), $audio->transcript('pt-PT') ?? $audio->transcript('en-GB')]);
        self::assertSame([], $this->editor->update($audio, [], ['en-GB' => 'Hello and welcome.'], null, 1, 'en-GB'));
        self::assertSame(['transcript'], $this->editor->update($audio, [], ['pt-PT' => 'Olá.'], null, 1, 'en-GB'));
        self::assertSame('Olá.', $audio->transcript('pt-PT'));
    }

    public function testRefusesATranscriptForAPicture(): void
    {
        $picture = $this->fixture->uploader()->upload($this->fixture->file('a.png', MediaFixture::png()), 'a.png', 1)->media;

        $this->expectException(MediaException::class);
        $this->editor->update($picture, [], ['en-GB' => 'Words'], null, 1);
    }

    public function testDeletesTheFileItsCopiesAndItsRow(): void
    {
        $picture = $this->fixture->uploader()->upload($this->fixture->file('big.jpg', MediaFixture::jpeg(1400, 900)), 'Big.jpg', 1)->media;
        $id = (int) $picture->id();
        self::assertNotSame([], $this->fixture->stored());
        $this->fixture->events = [];

        $this->editor->delete($picture, 7);

        self::assertSame([], $this->fixture->stored());
        self::assertNull($this->fixture->repository()->find($id));
        self::assertEquals([new MediaDeleted($id, 'Big.jpg', 7)], $this->fixture->events);
    }

    public function testAVideoGetsCaptionsPerLanguagePublishedWithIt(): void
    {
        $video = $this->saved('video/mp4', MediaKind::Video, 'mp4', 1);
        $english = $this->saved('text/vtt', MediaKind::Document, 'vtt', 2);
        $portuguese = $this->saved('text/vtt', MediaKind::Document, 'vtt', 3);
        $this->fixture->events = [];

        $this->editor->setCaptions($video, 'pt-PT', $portuguese, 7, true);
        $this->editor->setCaptions($video, 'en-GB', $english, 7, true);
        self::assertSame(['en-GB' => $english->id(), 'pt-PT' => $portuguese->id()], $video->captionFiles());
        self::assertFalse($english->isPublished(), 'an unpublished video keeps its captions private');
        /** @var list<object> $events */
        $events = $this->fixture->events;
        self::assertInstanceOf(MediaChanged::class, $events[0]);
        self::assertSame(['captions'], $events[0]->fields);

        $this->editor->update($video, [], null, true, 7);
        self::assertTrue($english->isPublished());
        self::assertTrue($portuguese->isPublished());

        $late = $this->saved('text/vtt', MediaKind::Document, 'vtt', 4);
        $this->editor->setCaptions($video, 'es', $late, 7, false);
        self::assertFalse($late->isPublished(), 'only someone who may publish publishes it');
        $this->editor->setCaptions($video, 'es', $late, 7, true);
        self::assertTrue($late->isPublished());
        $this->editor->setCaptions($video, 'es', $late, 7, true);

        $this->editor->update($video, [], null, false, 7);
        $this->editor->update($video, [], null, true, 7);
        self::assertTrue($late->isPublished());

        $this->editor->setCaptions($video, 'es', null, 7, true);
        self::assertArrayNotHasKey('es', $video->captionFiles());
    }

    public function testDeletingACaptionsFileTakesItOffItsVideos(): void
    {
        $video = $this->saved('video/mp4', MediaKind::Video, 'mp4', 1);
        $other = $this->saved('video/mp4', MediaKind::Video, 'mp4', 2);
        $captions = $this->fixture->uploader()->upload($this->fixture->file('a.vtt', "WEBVTT\n"), 'a.vtt', 1)->media;
        $kept = $this->saved('text/vtt', MediaKind::Document, 'vtt', 3);
        $this->editor->setCaptions($video, 'en-GB', $captions, 7, false);
        $this->editor->setCaptions($video, 'pt-PT', $captions, 7, false);
        $this->editor->setCaptions($other, 'en-GB', $kept, 7, false);

        $this->editor->delete($captions, 7);

        self::assertSame([], $video->captionFiles());
        self::assertSame(['en-GB' => $kept->id()], $other->captionFiles());
    }

    public function testRefusesCaptionsThatArentWebVtt(): void
    {
        $video = $this->saved('video/mp4', MediaKind::Video, 'mp4', 1);
        $pdf = $this->saved('application/pdf', MediaKind::Document, 'pdf', 2);

        $this->expectException(MediaException::class);
        $this->expectExceptionMessage('Choose a captions file in WebVTT format (.vtt).');
        $this->editor->setCaptions($video, 'en-GB', $pdf, 7, true);
    }

    public function testATranscriptFromTheAssistantStaysMarkedUntilChecked(): void
    {
        $audio = $this->saved('audio/mpeg', MediaKind::Audio, 'mp3', 1);

        self::assertSame(['transcript'], $this->editor->update($audio, [], ['en-GB' => 'Tidied.'], null, 7, 'en-GB', ['en-GB' => true]));
        self::assertTrue($audio->isTranscriptSuggested('en-GB'));
        self::assertSame([], $this->editor->update($audio, [], ['en-GB' => 'Tidied.'], null, 7, 'en-GB'), 'the same text keeps the mark');
        self::assertTrue($audio->isTranscriptSuggested('en-GB'));
        self::assertSame(['transcript'], $this->editor->update($audio, [], ['en-GB' => 'Tidied.'], null, 7, 'en-GB', ['en-GB' => false]), 'checking it is a change');
        self::assertFalse($audio->isTranscriptSuggested('en-GB'));

        $this->editor->update($audio, [], ['en-GB' => 'Tidied.'], null, 7, 'en-GB', ['en-GB' => true]);
        $this->editor->update($audio, [], ['en-GB' => 'Changed by a person.'], null, 7, 'en-GB');
        self::assertFalse($audio->isTranscriptSuggested('en-GB'), 'changing the text clears the mark');
    }

    /**
     * @param non-empty-string $type
     * @param non-empty-string $extension
     */
    private function saved(string $type, MediaKind $kind, string $extension, int $number): Media
    {
        $media = new Media(sprintf('%032x', 5000 + $number), sprintf('%064x', 5000 + $number), new MediaFormat($type, $kind, $extension, $extension), 'file-' . $number . '.' . $extension, 'file-' . $number . '.' . $extension, 10, null, null, [], 1, new DateTimeImmutable());
        $this->fixture->repository()->save($media);

        return $media;
    }
}
