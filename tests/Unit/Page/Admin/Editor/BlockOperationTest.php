<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Editor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Page\Admin\Editor\BlockOperation;
use SightPress\Core\Page\Admin\Editor\OperationResult;
use SightPress\Core\Tests\Support\BlockFixture;

#[CoversClass(BlockOperation::class)]
#[CoversClass(OperationResult::class)]
#[UsesNamespace('SightPress\Core')]
final class BlockOperationTest extends TestCase
{
    private const string SAVE = ' Save the page to keep this change.';

    private BlockOperation $operations;

    /** @var list<array{type: string, version: int, data: array<string, mixed>}> */
    private array $blocks;

    protected function setUp(): void
    {
        $this->operations = new BlockOperation(new BlockFixture([new GalleryBlock()])->types);
        $this->blocks = [
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'One', 'level' => 2]],
            ['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Two</p>']],
            ['type' => 'gallery', 'version' => 1, 'data' => ['images' => [['media' => 1], ['media' => 2]]]],
            ['type' => 'columns', 'version' => 1, 'data' => ['columns' => [['blocks' => []], ['blocks' => []]]]],
            ['type' => 'gone.block', 'version' => 1, 'data' => []],
        ];
    }

    public function testAddsABlockOfTheChosenTypeAtTheEnd(): void
    {
        $result = $this->operations->apply('en-GB', $this->blocks, 'add:/blocks', 'heading');

        self::assertNotNull($result);
        self::assertCount(6, $result->blocks);
        self::assertSame(['type' => 'heading', 'version' => 1, 'data' => []], $result->blocks[5]);
        self::assertSame('Added a Heading block. It is block 6 of 6.' . self::SAVE, $result->message);
        self::assertSame('en-gb-blocks-5', $result->focus);
        self::assertNull($this->operations->apply('en-GB', $this->blocks, 'add:/blocks', 'no_such_type'));
        self::assertNull($this->operations->apply('en-GB', $this->blocks, 'add:/blocks'));
    }

    public function testAddsPicturesColumnsAndBlocksInsideColumns(): void
    {
        $picture = $this->operations->apply('en-GB', $this->blocks, 'add:/blocks/2/data/images');
        self::assertNotNull($picture);
        self::assertSame([['media' => 1], ['media' => 2], []], $picture->blocks[2]['data']['images']);
        self::assertSame('Added picture 3 of 3.' . self::SAVE, $picture->message);
        self::assertSame('en-gb-blocks-2-data-images-2', $picture->focus);

        $column = $this->operations->apply('en-GB', $this->blocks, 'add:/blocks/3/data/columns');
        self::assertNotNull($column);
        self::assertSame([["blocks" => []], ["blocks" => []], []], $column->blocks[3]['data']['columns']);
        self::assertSame('Added column 3 of 3.' . self::SAVE, $column->message);

        $inside = $this->operations->apply('en-GB', $this->blocks, 'add:/blocks/3/data/columns/1/blocks', 'rich_text');
        self::assertNotNull($inside);
        self::assertSame([['blocks' => []], ['blocks' => [['type' => 'rich_text', 'version' => 1, 'data' => []]]]], $inside->blocks[3]['data']['columns']);
        self::assertSame('en-gb-blocks-3-data-columns-1-blocks-0', $inside->focus);
    }

    public function testMovesUpAndDownWithFocusOnTheSameButtonWhileItIsThere(): void
    {
        $up = $this->operations->apply('en-GB', $this->blocks, 'up:/blocks/2');
        self::assertNotNull($up);
        self::assertSame(['gallery', 'rich_text'], [$up->blocks[1]['type'], $up->blocks[2]['type']]);
        self::assertSame('Moved block 3 (Gallery) up. It is now block 2 of 5.' . self::SAVE, $up->message);
        self::assertSame('en-gb-blocks-1-up', $up->focus);

        $top = $this->operations->apply('en-GB', $this->blocks, 'up:/blocks/1');
        self::assertSame('en-gb-blocks-0-down', $top?->focus, 'first now: focus moves to "Move down"');

        $down = $this->operations->apply('en-GB', $this->blocks, 'down:/blocks/0');
        self::assertSame('en-gb-blocks-1-down', $down?->focus);
        $bottom = $this->operations->apply('en-GB', $this->blocks, 'down:/blocks/3');
        self::assertNotNull($bottom);
        self::assertSame('en-gb-blocks-4-up', $bottom->focus, 'last now: focus moves to "Move up"');
        self::assertSame('Moved block 4 (Columns) down. It is now block 5 of 5.' . self::SAVE, $bottom->message);

        $picture = $this->operations->apply('en-GB', $this->blocks, 'down:/blocks/2/data/images/0');
        self::assertNotNull($picture);
        self::assertSame([['media' => 2], ['media' => 1]], $picture->blocks[2]['data']['images']);
        self::assertSame('Moved picture 1 down. It is now picture 2 of 2.' . self::SAVE, $picture->message);
    }

    public function testRemovesWithFocusOnTheList(): void
    {
        $removed = $this->operations->apply('en-GB', $this->blocks, 'remove:/blocks/4');

        self::assertNotNull($removed);
        self::assertCount(4, $removed->blocks);
        self::assertSame('Removed block 5 (unknown).' . self::SAVE, $removed->message);
        self::assertSame('en-gb-blocks', $removed->focus);
    }

    public function testIgnoresButtonsThatDontMatchTheBlocks(): void
    {
        foreach ([
            'jump:/blocks/0', 'up:/blocks/0', 'down:/blocks/4', 'up:/blocks/9', 'up:/blocks/x', 'up:/other/0',
            'add:/other', 'add:/blocks/9/data/columns', 'add:/blocks/0/data/text', 'add:/blocks/0/x/y', 'add:/blocks/0/data',
            'add:/blocks/0/data/nothing', 'add:/blocks/4/data/x', 'remove:/blocks', 'up',
        ] as $op) {
            self::assertNull($this->operations->apply('en-GB', $this->blocks, $op, 'heading'), $op);
        }
    }
}
