<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Type;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Rule\LinkTextRule;
use SightPress\Core\Block\Type\CallToActionBlock;
use SightPress\Core\Tests\Support\BlockFixture;
use SightPress\Core\Tests\Support\PlainBlockContext;

#[CoversClass(CallToActionBlock::class)]
#[UsesNamespace('SightPress\Core')]
final class CallToActionBlockTest extends TestCase
{
    public function testDescribesItself(): void
    {
        $type = new CallToActionBlock();

        self::assertSame(['call_to_action', 'Call to action', 1], [$type->name(), $type->label(), $type->version()]);
        self::assertSame(['text', 'url'], $type->schema()['required']);
        self::assertSame(['text' => 'A'], $type->upgradeData(1, ['text' => 'A']));
        self::assertNull($type->structuredData([], new PlainBlockContext()));
        self::assertInstanceOf(LinkTextRule::class, $type->a11yRules()[0]);
    }

    public function testRendersALinkStyledAsAButton(): void
    {
        $type = new CallToActionBlock();
        $context = new PlainBlockContext();

        self::assertSame('<p class="block-call-to-action"><a class="button" href="/visit?a=1&amp;b=2">Book a visit</a></p>', $type->render(['text' => 'Book a visit', 'url' => '/visit?a=1&b=2'], $context));
        self::assertSame('<p class="block-call-to-action"><a class="button button--secondary" href="https://example.org">Donate &lt;now&gt;</a></p>', $type->render(['text' => 'Donate <now>', 'url' => 'https://example.org', 'style' => 'secondary'], $context));
    }

    public function testMarkdownLinksAreAbsoluteOnTheSite(): void
    {
        $type = new CallToActionBlock();
        $site = new BlockFixture()->context();

        self::assertSame('[Book a \[free\] visit](https://demo.example/visit%20us%29)', $type->renderMarkdown(['text' => 'Book a [free] visit', 'url' => '/visit us)'], $site));
        self::assertSame('[Mail us](mailto:hi@example.org)', $type->renderMarkdown(['text' => 'Mail us', 'url' => 'mailto:hi@example.org'], $site));
        self::assertSame('[Visit](/visit)', $type->renderMarkdown(['text' => 'Visit', 'url' => '/visit'], new PlainBlockContext()));
    }
}
