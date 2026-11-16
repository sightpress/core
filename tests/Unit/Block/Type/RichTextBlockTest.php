<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\RichTextLinksRule;
use SightPress\Core\Block\Type\RichTextBlock;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(RichTextBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class RichTextBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new RichTextBlock();

        self::assertSame(['rich_text', 'Text', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertStringContainsString('"contentMediaType":"text\\/html"', (string) json_encode($type->schema()));
        self::assertSame(['html' => 'A'], $type->upgradeData(1, ['html' => 'A']));
        self::assertNull($type->structuredData([], new PlainBlockContext()));
        self::assertInstanceOf(RichTextLinksRule::class, $type->a11yRules()[0]);
    }

    public function testOutputsTheSanitisedHtml(): void
    {
        $type = new RichTextBlock();

        self::assertSame('<div class="block-rich-text"><p>Hi</p></div>', $type->render(['html' => '<p>Hi</p>'], new PlainBlockContext()));
        self::assertSame('', $type->render([], new PlainBlockContext()));
    }

    public function testMarkdownLinksAreAbsoluteOnTheSite(): void
    {
        $type = new RichTextBlock();
        $html = ['html' => '<p>See <a href="/about">who we are</a>.</p>'];

        self::assertSame('See [who we are](https://demo.example/about).', $type->renderMarkdown($html, new BlockFixture()->context()));
        self::assertSame('See [who we are](/about).', $type->renderMarkdown($html, new PlainBlockContext()));
    }
}
