<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\User\Avatar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\User\Avatar\AvatarGenerator;

#[CoversClass(AvatarGenerator::class)]
final class AvatarGeneratorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string, string}>
     */
    public static function names(): iterable
    {
        yield 'accents and letters outside ASCII' => ['Zoë', 'Ødegaard', null, 'ZØ'];
        yield 'CJK names' => ['李', '小龙', 'zh-CN', '李小'];
        yield 'lower case' => ['ada', 'lovelace', 'en-GB', 'AL'];
        yield 'a combining accent stays with its letter' => ["e\u{0301}mile", 'zola', null, 'ÉZ'];
        yield 'a letter with a combining mark that has no single code point' => ["n\u{0308}ina", 'ng', null, "N\u{0308}N"];
        yield 'Turkish dotted i' => ['ilkay', 'yılmaz', 'tr-TR', 'İY'];
        yield 'the dotted i elsewhere' => ['ilkay', 'yılmaz', 'en-GB', 'IY'];
        yield 'surrounding spaces' => ['  Ada ', ' Lovelace', null, 'AL'];
        yield 'an emoji sequence is one grapheme' => ["\u{1F469}\u{200D}\u{1F469}\u{200D}\u{1F467} Fam", 'Smith', null, "\u{1F469}\u{200D}\u{1F469}\u{200D}\u{1F467}S"];
        yield 'an empty last name' => ['Ada', '', null, 'A'];
    }

    #[DataProvider('names')]
    public function testInitialsAreTheFirstGraphemeOfEachName(string $first, string $last, ?string $locale, string $expected): void
    {
        self::assertSame($expected, new AvatarGenerator()->initials($first, $last, $locale));
    }

    public function testEveryColourHasEnoughContrastWithTheInitials(): void
    {
        foreach (AvatarGenerator::PALETTE as $color) {
            self::assertGreaterThanOrEqual(4.5, self::contrast($color, AvatarGenerator::TEXT_COLOR), $color);
        }
        self::assertCount(count(AvatarGenerator::PALETTE), array_unique(AvatarGenerator::PALETTE));
    }

    public function testTheColourComesFromTheUserIdAndNeverChanges(): void
    {
        $generator = new AvatarGenerator();

        self::assertSame(AvatarGenerator::PALETTE[0], $generator->colorFor(0));
        self::assertSame(AvatarGenerator::PALETTE[3], $generator->colorFor(3));
        self::assertSame($generator->colorFor(13), $generator->colorFor(13 + count(AvatarGenerator::PALETTE)));
        self::assertSame(AvatarGenerator::PALETTE[2], $generator->colorFor(-2));
    }

    public function testTheSvgHoldsOnlyEscapedText(): void
    {
        $generator = new AvatarGenerator();

        $svg = $generator->svg('<&', '#1d4ed8');
        $long = $generator->svg('ABC', '#1d4ed8');

        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">', $svg);
        self::assertStringContainsString('<rect width="256" height="256" fill="#1d4ed8"/>', $svg);
        self::assertStringContainsString('fill="#ffffff"', $svg);
        self::assertStringContainsString('font-size="56" font-weight="600">&lt;&amp;</text>', $svg);
        self::assertStringContainsString('font-size="44"', $long);
        self::assertStringNotContainsString('<script', $svg);
        self::assertNotFalse(simplexml_load_string($svg));
    }

    private static function contrast(string $a, string $b): float
    {
        $lighter = max(self::luminance($a), self::luminance($b));
        $darker = min(self::luminance($a), self::luminance($b));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
