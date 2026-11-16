<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Html;
use SightPress\Core\Block\Rule\LinkText;
use SightPress\Core\Block\Rule\RichTextLinksRule;
use SightPress\Module\Block\A11ySeverity;

#[CoversClass(RichTextLinksRule::class)]
#[UsesClass(Html::class)]
#[UsesClass(LinkText::class)]
final class RichTextLinksRuleTest extends TestCase
{
    public function testAcceptsTextWithoutLinksOrWithClearOnes(): void
    {
        $rule = new RichTextLinksRule();

        self::assertSame([], $rule->check(['html' => '<p>No links here.</p>']));
        self::assertSame([], $rule->check([]));
        self::assertSame([], $rule->check(['html' => '<p>See <a href="/report">the annual report</a> and <a>an anchor</a>.</p>']));
    }

    public function testFindsEmptyAndVagueLinks(): void
    {
        $issues = new RichTextLinksRule()->check(['html' => '<p><a href="/a"> </a><a href="/b"><strong>Read more</strong></a> <a href="/c">here</a> <a href="/d">here</a></p>']);

        self::assertCount(2, $issues);
        self::assertSame(['rich_text.link_empty', A11ySeverity::Error, '/html', ['2.4.4', '4.1.2']], [$issues[0]->rule, $issues[0]->severity, $issues[0]->field, $issues[0]->wcag]);
        self::assertSame('1 link has no text. Give every link text that says where it goes.', $issues[0]->message);
        self::assertSame(['rich_text.link_vague', A11ySeverity::Warning], [$issues[1]->rule, $issues[1]->severity]);
        self::assertSame('Say where these links go: "Read more", "here".', $issues[1]->message);
    }

    public function testCountsEmptyLinks(): void
    {
        $issues = new RichTextLinksRule()->check(['html' => '<a href="/a"></a><a href="/b"></a>']);

        self::assertSame('2 links have no text. Give every link text that says where it goes.', $issues[0]->message);
    }
}
