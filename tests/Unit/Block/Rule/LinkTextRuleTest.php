<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\LinkText;
use SightPress\Core\Block\Rule\LinkTextRule;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(LinkText::class)]
#[CoversClass(LinkTextRule::class)]
#[UsesClass(Html::class)]
final class LinkTextRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function texts(): iterable
    {
        yield 'clear' => ['Read the annual report', false];
        yield 'click here' => ['Click here', true];
        yield 'punctuation' => ['Read more…', true];
        yield 'spaces' => ["  find   out\tmore ", true];
        yield 'address' => ['https://example.org/report', true];
        yield 'www' => ['www.example.org', true];
        yield 'more in a sentence' => ['More about our team', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('texts')]
    public function testKnowsVagueLinkText(string $text, bool $vague): void
    {
        self::assertSame($vague, LinkText::isVague($text));
    }

    public function testWarnsAboutTheField(): void
    {
        $rule = new LinkTextRule('call_to_action.text_vague', 'text');

        self::assertSame([], $rule->check(['text' => 'Book a visit']));
        $issues = $rule->check(['text' => 'Learn more']);
        self::assertCount(1, $issues);
        self::assertSame('call_to_action.text_vague', $issues[0]->rule);
        self::assertSame(A11ySeverity::Warning, $issues[0]->severity);
        self::assertSame('/text', $issues[0]->field);
        self::assertSame(['2.4.4'], $issues[0]->wcag);
    }
}
