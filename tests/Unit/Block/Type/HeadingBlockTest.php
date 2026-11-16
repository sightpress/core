<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Html;
use SightPress\Core\Block\Type\HeadingBlock;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(HeadingBlock::class)]
#[UsesClass(Html::class)]
final class HeadingBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new HeadingBlock();

        self::assertSame(['heading', 'Heading', 1, []], [$type->name(), $type->label(), $type->version(), $type->a11yRules()]);
        self::assertSame(['text', 'level'], $type->schema()['required']);
        self::assertSame(['text' => 'A'], $type->upgradeData(1, ['text' => 'A']));
        self::assertNull($type->structuredData(['text' => 'A', 'level' => 2], new PlainBlockContext()));
    }

    public function testRendersALevelTwoToSixHeading(): void
    {
        $type = new HeadingBlock();
        $context = new PlainBlockContext();

        self::assertSame('<h3 class="block-heading">Opening &amp; closing times</h3>', $type->render(['text' => 'Opening & closing times', 'level' => 3], $context));
        self::assertSame('<h2 class="block-heading">Top</h2>', $type->render(['text' => 'Top', 'level' => 1], $context));
        self::assertSame('<h6 class="block-heading">Deep</h6>', $type->render(['text' => 'Deep', 'level' => 9], $context));
        self::assertSame('<h2 class="block-heading"></h2>', $type->render(['level' => '4'], $context));
        self::assertSame('#### Prices \*from\* £5', $type->renderMarkdown(['text' => 'Prices *from* £5', 'level' => 4], $context));
    }
}
