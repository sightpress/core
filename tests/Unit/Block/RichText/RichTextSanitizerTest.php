<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\RichText;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\RichText\RichTextSanitizer;
use SightPress\Core\Block\RichText\StyleSanitizer;

#[CoversClass(RichTextSanitizer::class)]
#[CoversClass(StyleSanitizer::class)]
final class RichTextSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function html(): iterable
    {
        yield 'allowed' => ['<p lang="fr" dir="ltr">Bonjour <strong>tout</strong> <em>le</em> monde</p>', '<p lang="fr" dir="ltr">Bonjour <strong>tout</strong> <em>le</em> monde</p>'];
        yield 'lists' => ['<ol start="3" reversed><li>One</li></ol><ul><li>Two</li></ul>', '<ol start="3" reversed><li>One</li></ol><ul><li>Two</li></ul>'];
        yield 'headings' => ['<h1>Title</h1><h2>Section</h2>', 'Title<h2>Section</h2>'];
        yield 'script' => ['<p>Hi<script>alert(1)</script></p>', '<p>Hi</p>'];
        yield 'handler' => ['<p onclick="x()">Hi</p>', '<p>Hi</p>'];
        yield 'iframe and image' => ['<iframe src="https://x"></iframe><img src="a.jpg" alt="A">', ''];
        yield 'link schemes' => ['<a href="https://example.org">A</a><a href="/about">B</a><a href="mailto:a@example.org">C</a><a href="javascript:alert(1)">D</a>', '<a href="https://example.org">A</a><a href="/about">B</a><a href="mailto:a&#64;example.org">C</a><a>D</a>'];
        yield 'colours kept' => ['<span style="color:#1A1A1A; background-color: #fff; font-size: 40px">x</span>', '<span style="background-color: #fff; color: #1a1a1a">x</span>'];
        yield 'other styles dropped' => ['<mark style="position: fixed; color: red">x</mark>', '<mark>x</mark>'];
        yield 'style not allowed on p' => ['<p style="color: #000">x</p>', '<p>x</p>'];
        yield 'wrappers unwrapped' => ['<div><section>Pasted <u>text</u></section></div>', 'Pasted text'];
        yield 'trimmed' => ["  <p>x</p>\n", '<p>x</p>'];
    }

    #[DataProvider('html')]
    public function testCleansHtmlWithAnAllowList(string $html, string $expected): void
    {
        self::assertSame($expected, new RichTextSanitizer()->sanitize($html));
    }

    public function testStyleSanitizerAppliesToStyleOnAnyElement(): void
    {
        $sanitizer = new StyleSanitizer();

        self::assertNull($sanitizer->getSupportedElements());
        self::assertSame(['style'], $sanitizer->getSupportedAttributes());
    }
}
