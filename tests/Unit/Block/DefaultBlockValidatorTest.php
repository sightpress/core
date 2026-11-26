<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\BlockIssue;
use SightPress\Core\Block\Contract\BlockViolation;
use SightPress\Core\Block\Contract\InvalidBlocks;
use SightPress\Core\Block\Contract\ValidatedBlocks;
use SightPress\Core\Block\DefaultBlockValidator;
use SightPress\Core\Media\Contract\MediaKind;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\MediaKindsBlockType;
use SightPress\Core\Tests\Support\VersionedBlockType;

#[CoversClass(DefaultBlockValidator::class)]
#[CoversClass(BlockIssue::class)]
#[CoversClass(BlockViolation::class)]
#[CoversClass(InvalidBlocks::class)]
#[CoversClass(ValidatedBlocks::class)]
#[UsesNamespace('SightPress\Core')]
final class DefaultBlockValidatorTest extends TestCase
{
    private BlockFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new BlockFixture([new VersionedBlockType()]);
    }

    public function testAcceptsUpgradesAndCleansBlocks(): void
    {
        $picture = $this->fixture->addMedia();
        $icon = $this->fixture->addMedia(kind: MediaKind::Document);

        $result = $this->fixture->validator()->validate([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Visit us', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p onclick="x()">Hi <a href="/about">here</a></p><script>x</script>']],
            ['type' => 'acme.badge', 'version' => 1, 'data' => ['text' => 'TODO']],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture]]]],
                ['blocks' => [['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'New', 'icon' => $icon]]]],
            ]]],
        ]);

        self::assertSame(['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Hi <a href="/about">here</a></p>']], Text::of($result->blocks[1]));
        self::assertSame(['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'TODO']], Text::of($result->blocks[2]));
        self::assertSame(['columns' => [
            ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture]]]],
            ['blocks' => [['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'New', 'icon' => $icon]]]],
        ]], Text::of($result->blocks[3]['data']));
        self::assertSame([$picture, $icon], Text::of($result->mediaIds));

        self::assertSame(
            [['/1', 'rich_text', 'rich_text.link_vague', '/1/data/html'], ['/2', 'acme.badge', 'acme.badge_todo', '/2/data/label'], ['/3/data/columns/0/blocks/0', 'image', 'image.alt_missing', '/3/data/columns/0/blocks/0/data/alt']],
            Text::of(array_map(static fn (BlockIssue $issue): array => [$issue->pointer, $issue->type, $issue->issue->rule, $issue->fieldPointer()], $result->issues)),
        );
        self::assertSame(['image.alt_missing'], Text::of(array_map(static fn (BlockIssue $issue): string => $issue->issue->rule, $result->errors())));
        self::assertCount(2, $result->warnings());
    }

    public function testKeepsTheLooksThatAreNotTheDefault(): void
    {
        $result = $this->fixture->validator()->validate([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Visit us', 'level' => 2], 'styles' => ['align' => 'center', 'spacing' => 'medium']],
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Plain', 'level' => 2], 'styles' => ['align' => 'start']],
            ['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'New'], 'styles' => []],
        ]);

        self::assertSame(['align' => 'center'], $result->blocks[0]['styles'] ?? null);
        self::assertArrayNotHasKey('styles', $result->blocks[1]);
        self::assertArrayNotHasKey('styles', $result->blocks[2]);
    }

    public function testRefusesLooksTheTypeDoesNotOffer(): void
    {
        try {
            $this->fixture->validator()->validate([
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'A', 'level' => 2], 'styles' => ['align' => 'justify', 'width/x' => 'wide']],
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'B', 'level' => 2], 'styles' => 'center'],
                ['type' => 'acme.badge', 'version' => 2, 'data' => ['label' => 'C'], 'styles' => ['align' => 'end']],
            ]);
            self::fail('Expected the blocks to be refused.');
        } catch (InvalidBlocks $exception) {
            self::assertSame(
                [
                    ['/0/styles/align', 'Choose one of: start, center, end.'],
                    ['/0/styles/width~1x', 'This block has no "width/x" choice.'],
                    ['/1/styles', 'The look of this block must be a set of choices, such as align: center.'],
                    ['/2/styles/align', 'This block has no "align" choice.'],
                ],
                Text::of(array_map(static fn (BlockViolation $violation): array => [$violation->pointer, $violation->message], $exception->violations)),
            );
        }
    }

    public function testAcceptsNoBlocks(): void
    {
        $result = $this->fixture->validator()->validate([]);

        self::assertSame([[], [], []], Text::of([$result->blocks, $result->issues, $result->mediaIds]));
    }

    public function testCollectsEverythingThatIsWrong(): void
    {
        $picture = $this->fixture->addMedia();
        $document = $this->fixture->addMedia(kind: MediaKind::Document);

        try {
            $this->fixture->validator()->validate([
                'not a block',
                ['type' => 'heading', 'version' => 1, 'data' => ['a', 'b']],
                ['type' => 'heading', 'version' => '1', 'data' => []],
                ['type' => 'acme.missing', 'version' => 1, 'data' => []],
                ['type' => 'acme.badge', 'version' => 3, 'data' => ['label' => 'x']],
                ['type' => 'heading', 'version' => 1, 'data' => ['text' => '', 'level' => 7, 'colour' => 'red']],
                ['type' => 'image', 'version' => 1, 'data' => ['media' => 999_999, 'alt' => 'Gone']],
                ['type' => 'image', 'version' => 1, 'data' => ['media' => $document, 'alt' => 'Report']],
                ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
                    ['blocks' => [['type' => 'columns', 'version' => 1, 'data' => ['columns' => []]]]],
                    ['blocks' => [['type' => 'image', 'version' => 1, 'data' => ['media' => $picture, 'alt' => 5]]]],
                ]]],
            ]);
            self::fail('Expected the blocks to be refused.');
        } catch (InvalidBlocks $exception) {
            self::assertSame(
                [
                    ['/0', 'This is not a block: it needs a type, a version and its data.'],
                    ['/1', 'This is not a block: it needs a type, a version and its data.'],
                    ['/2', 'This is not a block: it needs a type, a version and its data.'],
                    ['/3/type', 'There is no block type "acme.missing" on this site. Remove the block or turn on the module that adds it.'],
                    ['/4/version', 'This block was saved by a newer version of the "Badge" block. Update the module that adds it.'],
                    ['/5/data/text', 'Fill in this field.'],
                    ['/5/data/level', 'Use 6 or less.'],
                    ['/5/data/colour', 'This field isn\'t allowed here.'],
                    ['/8/data/columns/0/blocks/0/type', 'A "Columns" block can\'t sit inside another block.'],
                    ['/8/data/columns/1/blocks/0/data/alt', 'This must be text.'],
                    ['/6/data/media', 'This file isn\'t in the media library any more. Choose another one.'],
                    ['/7/data/media', 'Choose a picture.'],
                ],
                Text::of(array_map(static fn (BlockViolation $violation): array => [$violation->pointer, $violation->message], $exception->violations)),
            );
            self::assertStringStartsWith('The blocks can\'t be saved yet. /0: This is not a block', $exception->getMessage());
        }
    }

    public function testNamesTheKindOfFileEachFieldNeeds(): void
    {
        $picture = $this->fixture->addMedia();
        $validator = new BlockFixture([new MediaKindsBlockType()], $this->fixture->database)->validator();
        $captions = $this->fixture->addCaptions();
        $pdf = $this->fixture->addMedia(kind: MediaKind::Document);
        self::assertSame([$captions, $pdf, $picture], Text::of($validator->validate([['type' => 'acme.media_kinds', 'version' => 1, 'data' => ['captions' => $captions, 'document' => $pdf, 'any' => $picture]]])->mediaIds));

        $this->expectException(InvalidBlocks::class);
        $this->expectExceptionMessage('/0/data/video: Choose a video. /0/data/audio: Choose an audio file. /0/data/document: Choose a document, such as a PDF. /0/data/captions: Choose a captions file in WebVTT format (.vtt).');

        $validator->validate([['type' => 'acme.media_kinds', 'version' => 1, 'data' => ['video' => $picture, 'audio' => $picture, 'document' => $picture, 'captions' => $pdf]]]);
    }
}
