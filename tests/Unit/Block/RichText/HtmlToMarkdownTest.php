<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\RichText;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\RichText\HtmlToMarkdown;

#[CoversClass(HtmlToMarkdown::class)]
final class HtmlToMarkdownTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function html(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'paragraphs' => ['<p>One  <strong>two</strong>  <em>three</em></p><p>Four</p>', "One **two** _three_\n\nFour"];
        yield 'loose text' => ['Loose <b>bold</b> text<p>Then a paragraph</p>after', "Loose **bold** text\n\nThen a paragraph\n\nafter"];
        yield 'headings' => ['<h2>Two</h2><h4>Four</h4>', "## Two\n\n#### Four"];
        yield 'inline' => ['<p><i>i</i> <cite>c</cite> <s>s</s> <code>a`b</code> <kbd>K</kbd> <q>q</q> <abbr title="x">AB</abbr> <strong></strong><em></em><s></s>end</p>', '_i_ _c_ ~~s~~ `a\`b` `K` "q" AB end'];
        yield 'line break' => ['<p>One<br>Two</p>', "One\\\nTwo"];
        yield 'links' => ['<p><a href="/about us">About)</a> <a href="https://example.org/a)">B</a> <a>Anchor</a> <a href="">Empty</a></p>', '[About)](https://site.example/about%20us) [B](https://example.org/a%29) Anchor Empty'];
        yield 'lists' => ['<ul><li>One<ul><li>Nested</li></ul></li><li>Two</li></ul><ol start="3"><li>Three</li><li>Four<ol><li>Deep</li></ol></li>text</ol>', "- One\n  - Nested\n- Two\n\n3. Three\n4. Four\n   1. Deep"];
        yield 'list start zero' => ['<ol start="0"><li>A</li><p>Stray</p></ol>', '1. A'];
        yield 'quote' => ['<blockquote><p>One</p><p>Two</p></blockquote>', "> One\n>\n> Two"];
        yield 'code' => ["<pre>line 1\n  line 2\n</pre>", "```\nline 1\n  line 2\n```"];
        yield 'rule' => ['<p>A</p><hr><p>B</p>', "A\n\n---\n\nB"];
        yield 'definitions' => ['<dl><dt>Term</dt><dd>Meaning</dd></dl>', "**Term**\n: Meaning"];
        yield 'escaped' => ['<p># Not a heading *or* [link]</p>', '\# Not a heading \*or\* \[link\]'];
        yield 'comment' => ['<p>A<!-- note -->B</p>', 'AB'];
    }

    #[DataProvider('html')]
    public function testConvertsRichText(string $html, string $markdown): void
    {
        $converter = new HtmlToMarkdown(static fn (string $href): string => str_starts_with($href, '/') ? 'https://site.example' . $href : $href);

        self::assertSame($markdown, $converter->convert($html));
    }
}
