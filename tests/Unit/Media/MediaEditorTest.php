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
}
