<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Page\Publish;

use SightPress\Core\Tests\Support\Text;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Page\Publish\Contrast;
use SightPress\Core\Page\Publish\HtmlChecks;

#[CoversClass(HtmlChecks::class)]
#[UsesClass(Contrast::class)]
#[UsesNamespace('SightPress\Core\Template')]
#[UsesNamespace('SightPress\Core\Tenant')]
final class HtmlChecksTest extends TestCase
{
    private const array MODES = [
        'light mode' => ['text' => '#1a1a1f', 'background' => '#ffffff'],
        'dark mode' => ['text' => '#ececf1', 'background' => '#121216'],
    ];

    public function testHeadingsStartBelowThePageTitleAndDontSkip(): void
    {
        $level = 1;

        self::assertSame([], Text::of(HtmlChecks::check('<h2>A</h2><p>x</p><h3>B</h3><h2>C</h2>', $level, self::MODES)));
        self::assertSame(2, $level);
        self::assertSame([], Text::of(HtmlChecks::check('', $level, self::MODES)));
        $issues = HtmlChecks::check('<h4>Too deep</h4><h1>Second title</h1><h3>Fine after h1?</h3>', $level, self::MODES);

        self::assertSame(['page.heading_skipped', 'page.heading_h1', 'page.heading_skipped'], Text::of(array_column($issues, 'rule')));
        self::assertSame('This heading jumps from level 2 to level 4. Use level 3, or add the missing level before it.', Text::of($issues[0]['message']));
        self::assertSame(['1.3.1'], Text::of($issues[0]['wcag']));
        self::assertSame('This heading jumps from level 1 to level 3. Use level 2, or add the missing level before it.', Text::of($issues[2]['message']));
        self::assertSame(3, $level);
    }

    public function testPicturesNeedAlt(): void
    {
        $level = 1;

        self::assertSame([], Text::of(HtmlChecks::check('<img src="a.jpg" alt=""><img src="b.jpg" alt="B">', $level, self::MODES)));
        self::assertSame(['page.image_alt'], Text::of(array_column(HtmlChecks::check('<p><img src="c.jpg"></p>', $level, self::MODES), 'rule')));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function languages(): iterable
    {
        yield 'french' => ['fr', true];
        yield 'brazilian portuguese' => ['pt-BR', true];
        yield 'welsh' => ['cy', true];
        yield 'word' => ['english', false];
        yield 'unknown code' => ['qq', false];
        yield 'empty' => ['', false];
        yield 'underscore' => ['pt_BR', false];
    }

    #[DataProvider('languages')]
    public function testLanguageCodesMustBeReal(string $tag, bool $valid): void
    {
        $level = 1;

        self::assertSame($valid, HtmlChecks::isLanguage($tag));
        self::assertSame($valid ? [] : ['page.lang_invalid'], array_column(HtmlChecks::check('<p lang="' . $tag . '">x</p>', $level, self::MODES), 'rule'));
    }

    public function testColoursChosenInTheContentMustBeReadableInEveryMode(): void
    {
        $level = 1;
        $check = static function (string $html) use (&$level): array {
            return HtmlChecks::check($html, $level, self::MODES);
        };

        $dark = $check('<p><span style="color: #0a3d91">Dark blue</span></p>');
        self::assertSame(['page.contrast'], Text::of(array_column($dark, 'rule')));
        self::assertSame('The text colour #0a3d91 on #121216 has a contrast of 1.85:1 in dark mode; it needs at least 4.5:1. Choose another colour, add a background colour that goes with it, or remove it.', Text::of($dark[0]['message']));
        self::assertSame(['1.4.3'], Text::of($dark[0]['wcag']));

        self::assertSame([], Text::of($check('<p style="background-color: #fff2a8"><span style="color: #1a1a1f">Readable on yellow in both modes</span></p>')));
        $white = $check('<mark style="background-color: #ffffff">A white background with the theme\'s text colour</mark>');
        self::assertStringStartsWith('The text colour #ececf1 on #ffffff', (string) $white[0]['message'], 'dark mode has light text');
        $pair = $check('<span style="color: #777777; background-color: #ffffff">Grey on white</span>');
        self::assertSame('The text colour #777777 on #ffffff has a contrast of 4.47:1; it needs at least 4.5:1. Choose another colour, add a background colour that goes with it, or remove it.', Text::of($pair[0]['message']));
        self::assertSame([], Text::of($check('<span style="color: #777777"></span>')), 'no text, nothing to read');
        self::assertSame([], Text::of($check('<span style="font-weight: bold; color: red">Not a hex colour</span>')));
        self::assertSame(['page.contrast'], Text::of(array_column($check('<div style="background-color: #000"><p style="color: #222">Inherits the black background</p></div>'), 'rule')));
    }
}
