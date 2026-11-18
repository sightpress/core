<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Contract;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\MediaTexts;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PageFixture;

#[CoversClass(MediaTexts::class)]
#[UsesNamespace('SightPress\Core')]
final class MediaTextsTest extends TestCase
{
    private BlockFixture $blocks;

    private MediaTexts $texts;

    private int $picture;

    private int $audio;

    protected function setUp(): void
    {
        $this->blocks = new BlockFixture();
        $now = new DateTimeImmutable();
        $this->picture = $this->blocks->addMedia();
        $picture = $this->blocks->media()->find($this->picture) ?? throw new \LogicException();
        $picture->describe('en-GB', 'A lighthouse', 'Sagres, 2026', $now);
        $picture->describe('pt-BR', 'Um farol', null, $now);
        $this->blocks->media()->save($picture);
        $this->audio = $this->blocks->addMedia(kind: MediaKind::Audio, fileName: 'talk.mp3');
        $audio = $this->blocks->media()->find($this->audio) ?? throw new \LogicException();
        $audio->setTranscript('en-GB', 'Hello.', $now);
        $this->blocks->media()->save($audio);
        $this->texts = new MediaTexts($this->blocks->media(), PageFixture::site(['en-GB', 'pt-BR']));
    }

    public function testFillsWhatABlockLeftEmptyInThePagesLanguageOrElseTheMainOne(): void
    {
        $blocks = [
            self::block('image', ['media' => $this->picture]),
            self::block('image', ['media' => $this->picture, 'alt' => 'Own words', 'caption' => ' ']),
            self::block('image', ['media' => $this->picture, 'decorative' => true]),
            self::block('gallery', ['images' => [['media' => $this->picture], 'not a picture']]),
            self::block('audio', ['media' => $this->audio, 'title' => 'Talk']),
            self::block('columns', ['columns' => [['blocks' => [self::block('image', ['media' => $this->picture])]]]]),
            self::block('file', ['media' => $this->picture]),
            self::block('image', ['media' => 999]),
            self::block('image', ['media' => 'x']),
        ];

        $filled = $this->texts->fill($blocks, 'pt-BR');

        self::assertSame(['media' => $this->picture, 'alt' => 'Um farol', 'caption' => 'Sagres, 2026'], $filled[0]['data']);
        self::assertSame(['media' => $this->picture, 'alt' => 'Own words', 'caption' => 'Sagres, 2026'], $filled[1]['data']);
        self::assertSame(['media' => $this->picture, 'decorative' => true, 'caption' => 'Sagres, 2026'], $filled[2]['data']);
        self::assertSame([['media' => $this->picture, 'alt' => 'Um farol', 'caption' => 'Sagres, 2026'], 'not a picture'], $filled[3]['data']['images']);
        self::assertSame('Hello.', $filled[4]['data']['transcript']);
        self::assertStringContainsString('"alt":"Um farol"', (string) json_encode($filled[5]['data']['columns'], JSON_UNESCAPED_UNICODE));
        self::assertSame($blocks[6], $filled[6], 'other blocks are left alone');
        self::assertSame($blocks[7], $filled[7]);
        self::assertSame($blocks[8], $filled[8]);
        self::assertSame('A lighthouse', $this->texts->fill([$blocks[0]], 'en-GB')[0]['data']['alt']);
    }

    public function testLeavesBlocksWithoutFilesAloneAndLooksFilesUpOnce(): void
    {
        $heading = [self::block('heading', ['text' => 'Hi', 'level' => 2])];
        self::assertSame($heading, $this->texts->fill($heading, 'en-GB'));

        $this->texts->preload([[self::block('image', ['media' => $this->picture])], $heading]);
        $this->blocks->media()->remove($this->blocks->media()->find($this->picture) ?? throw new \LogicException());
        self::assertSame('A lighthouse', $this->texts->fill([self::block('image', ['media' => $this->picture])], 'en-GB')[0]['data']['alt']);
        self::assertSame(['media' => 999], $this->texts->fill([self::block('gallery', ['images' => 'none']), self::block('audio', ['media' => 999])], 'en-GB')[1]['data']);
    }

    public function testATranscriptTooLongForTheBlockIsLeftOut(): void
    {
        $audio = $this->blocks->media()->find($this->audio) ?? throw new \LogicException();
        $audio->setTranscript('en-GB', str_repeat('a', 100_001), new DateTimeImmutable());
        $this->blocks->media()->save($audio);

        self::assertArrayNotHasKey('transcript', $this->texts->fill([self::block('video', ['media' => $this->audio])], 'en-GB')[0]['data']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{type: string, version: int, data: array<string, mixed>}
     */
    private static function block(string $type, array $data): array
    {
        return ['type' => $type, 'version' => 1, 'data' => $data];
    }
}
