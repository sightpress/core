<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Html;

#[CoversClass(Html::class)]
final class HtmlTest extends TestCase
{
    public function testEscapes(): void
    {
        self::assertSame('&lt;b&gt; &quot;a&quot; &amp; &apos;b&apos;', Html::e('<b> "a" & \'b\''));
        self::assertSame('5', Html::e(5));
        self::assertSame('', Html::e(['x']));
        self::assertSame('', Html::e(null));
    }

    public function testReadsTextFromData(): void
    {
        self::assertSame('Hi', Html::text(['a' => 'Hi'], 'a'));
        self::assertSame('', Html::text(['a' => 5], 'a'));
        self::assertSame('', Html::text([], 'a'));
    }

    public function testEscapesMarkdown(): void
    {
        self::assertSame('\*Not\* \_bold\_ \# \[link\](x) \<b\> \`c\` a\\\\b', Html::markdown("  *Not* _bold_ #\n [link](x) <b> `c` a\\b "));
    }
}
