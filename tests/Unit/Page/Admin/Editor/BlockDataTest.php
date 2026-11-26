<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Admin\Editor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\ColumnsBlock;
use SightPress\Core\Block\Type\GalleryBlock;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Page\Admin\Editor\BlockData;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\EveryFieldBlockType;

#[CoversClass(BlockData::class)]
#[UsesNamespace('SightPress\Core')]
final class BlockDataTest extends TestCase
{
    private BlockData $data;

    protected function setUp(): void
    {
        $this->data = new BlockData(new BlockFixture([new GalleryBlock(), new EveryFieldBlockType()])->types);
    }

    public function testReadsTheFormBackIntoBlocksFollowingEachSchema(): void
    {
        $blocks = $this->data->read([
            '1' => ['type' => 'image', 'version' => '1', 'data' => ['media' => '12', 'alt' => '', 'decorative' => '1', 'caption' => "Two\r\nlines"]],
            '0' => ['type' => 'heading', 'version' => 'x', 'data' => ['text' => 'Hello', 'level' => '3']],
            '2' => 'not a block',
            '3' => ['type' => 'acme.every_field', 'data' => [
                'count' => ' 42 ', 'size' => '2', 'ratio' => '1.5', 'colour' => '7', 'contact' => 'a@example.org',
                'rows' => [['cells' => [['text' => 'A'], 'junk']], 'junk'],
            ]],
            '4' => ['type' => 'acme.every_field', 'data' => ['count' => 'many', 'ratio' => 'big', 'colour' => 'green', 'size' => '']],
            '5' => ['type' => 'gone.block', 'version' => '3', 'kept' => '{"keep":["me"]}'],
            '6' => ['type' => 'gone.block', 'kept' => 'not json'],
            '7' => ['version' => '1'],
        ]);

        self::assertSame([
            ['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Hello', 'level' => 3]],
            ['type' => 'image', 'version' => 1, 'data' => ['media' => 12, 'decorative' => true, 'caption' => "Two\nlines"]],
            ['type' => 'acme.every_field', 'version' => 1, 'data' => [
                'agreed' => false, 'count' => 42, 'size' => 2, 'ratio' => 1.5, 'colour' => 7, 'contact' => 'a@example.org',
                'rows' => [['cells' => [['text' => 'A'], []]], ['cells' => []]],
            ]],
            ['type' => 'acme.every_field', 'version' => 1, 'data' => ['agreed' => false, 'count' => 'many', 'ratio' => 'big', 'colour' => 'green', 'rows' => []]],
            ['type' => 'gone.block', 'version' => 3, 'data' => ['keep' => ['me']]],
            ['type' => 'gone.block', 'version' => 1, 'data' => []],
        ], $blocks);
        self::assertSame([], $this->data->read('not a list'));
    }

    public function testKeepsTheLooksThatAreNotTheDefault(): void
    {
        $blocks = $this->data->read([
            ['type' => 'heading', 'version' => '1', 'data' => ['text' => 'A', 'level' => '2'], 'styles' => ['align' => 'center', 'spacing' => 'medium', 'width' => 'wide']],
            ['type' => 'heading', 'version' => '1', 'data' => ['text' => 'B', 'level' => '2'], 'styles' => ['align' => 'start']],
            ['type' => 'gone.block', 'version' => '1', 'kept' => '{}', 'styles' => ['align' => 'center']],
        ]);

        self::assertSame(['align' => 'center'], $blocks[0]['styles'] ?? null);
        self::assertArrayNotHasKey('styles', $blocks[1]);
        self::assertArrayNotHasKey('styles', $blocks[2]);
    }

    public function testReadsTheBlocksInsideColumns(): void
    {
        $blocks = $this->data->read([['type' => 'columns', 'version' => '1', 'data' => ['columns' => [
            ['item' => '1', 'blocks' => [['type' => 'heading', 'version' => '1', 'data' => ['text' => 'In', 'level' => '2']]]],
            ['item' => '1'],
        ]]]]);

        self::assertSame([['type' => 'columns', 'version' => 1, 'data' => ['columns' => [
            ['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'In', 'level' => 2]]]],
            ['blocks' => []],
        ]]]], $blocks);
    }

    public function testNewBlocksGetTheItemsTheyNeed(): void
    {
        self::assertSame(['type' => 'heading', 'version' => 1, 'data' => []], BlockData::blank(new HeadingBlock()));
        self::assertSame(['type' => 'columns', 'version' => 1, 'data' => ['columns' => [[], []]]], BlockData::blank(new ColumnsBlock()));
        self::assertSame(['type' => 'gallery', 'version' => 1, 'data' => ['images' => [[]]]], BlockData::blank(new GalleryBlock()));
        self::assertSame([], BlockData::skeleton(new EveryFieldBlockType()->schema()), 'empty lists are left out');
        self::assertSame([['cells' => [[]]]], BlockData::skeleton(['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => ['cells' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'properties' => []]]]]]));
        self::assertSame([], BlockData::skeleton(['type' => 'array', 'minItems' => 'two']));
        self::assertSame([], BlockData::skeleton(['type' => 'array', 'items' => ['type' => 'object']]));
        self::assertNull(BlockData::skeleton(['type' => 'string']));
    }

    public function testListsOnlyWellFormedProperties(): void
    {
        self::assertSame(['a' => ['type' => 'string']], BlockData::properties(['properties' => ['a' => ['type' => 'string'], 0 => [], 'b' => 'x']]));
        self::assertSame([], BlockData::properties([]));
    }
}
