<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Type\ColumnsBlock;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(ColumnsBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class ColumnsBlockTest extends TestCase
{
    private const array DATA = ['columns' => [
        ['blocks' => [['type' => 'heading', 'version' => 1, 'data' => ['text' => 'Left', 'level' => 2]]]],
        ['blocks' => []],
        ['blocks' => [['type' => 'rich_text', 'version' => 1, 'data' => ['html' => '<p>Right</p>']]]],
    ]];

    public function testDescribesItself(): void
    {
        $type = new ColumnsBlock();

        self::assertSame(['columns', 'Columns', 1, []], [$type->name(), $type->label(), $type->version(), $type->a11yRules()]);
        self::assertStringContainsString('"minItems":2,"maxItems":4', (string) json_encode($type->schema()));
        self::assertSame(['columns' => []], $type->upgradeData(1, ['columns' => []]));
        self::assertNull($type->structuredData(self::DATA, new PlainBlockContext()));
    }

    public function testExposesAndReplacesItsBlockLists(): void
    {
        $type = new ColumnsBlock();

        $lists = $type->childLists(self::DATA);
        self::assertSame(['/columns/0/blocks', '/columns/1/blocks', '/columns/2/blocks'], array_keys($lists));
        self::assertSame([], $lists['/columns/1/blocks']);
        self::assertSame([], $type->childLists(['columns' => 'x']));
        self::assertSame(['/columns/0/blocks' => [], '/columns/1/blocks' => []], $type->childLists(['columns' => ['x', ['blocks' => 'y']]]));

        $replaced = $type->withChildLists(['columns' => [['blocks' => []], ['blocks' => []]], 'x' => 1], ['/columns/1/blocks' => [['type' => 'heading', 'version' => 1, 'data' => []]]]);
        self::assertSame(['columns' => [['blocks' => []], ['blocks' => [['type' => 'heading', 'version' => 1, 'data' => []]]]], 'x' => 1], $replaced);
    }

    public function testRendersTheBlocksInEachColumnInReadingOrder(): void
    {
        $type = new ColumnsBlock();
        $context = new BlockFixture()->context();

        self::assertSame(
            '<div class="block-columns block-columns--3"><div class="block-column"><h2 class="block-heading">Left</h2></div><div class="block-column"></div><div class="block-column"><div class="block-rich-text"><p>Right</p></div></div></div>',
            $type->render(self::DATA, $context),
        );
        self::assertSame("## Left\n\nRight", $type->renderMarkdown(self::DATA, $context));
        self::assertSame('', $type->render(self::DATA, new PlainBlockContext()));
        self::assertSame('', $type->renderMarkdown(self::DATA, new PlainBlockContext()));
    }
}
