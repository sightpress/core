<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Branding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Branding\BrandColours;
use SightPress\Core\Branding\Initials;

#[CoversClass(Initials::class)]
#[CoversClass(BrandColours::class)]
final class InitialsTest extends TestCase
{
    public function testTakesTheFirstLetterOfTheFirstAndLastWords(): void
    {
        self::assertSame('RL', Initials::of('Riverside Library'));
        self::assertSame('RL', Initials::of('  riverside  public   library '));
        self::assertSame('D', Initials::of('demo'));
        self::assertSame('ZØ', Initials::of('Zoë Ødegaard'));
        self::assertSame('ÉO', Initials::of('école – Saint-Ouen'));
        self::assertSame("E\u{301}S", Initials::of("e\u{301}cole Saint"), 'a letter and its combining accent stay together');
        self::assertSame('李小', Initials::of('李 小龙'));
        self::assertSame('3A', Initials::of('3 Arts'));
        self::assertSame('A', Initials::of('(Acme)'));
        self::assertSame('?', Initials::of(' -- '));
    }

    public function testKnowsWhichTextTheFontCanDraw(): void
    {
        self::assertTrue(Initials::drawable('Riverside Library'));
        self::assertTrue(Initials::drawable('Zoë & Ødegaard’s Café'));
        self::assertTrue(Initials::drawable('Библиотека'));
        self::assertFalse(Initials::drawable('李小'));
        self::assertFalse(Initials::drawable(''));
    }

    public function testTheBrandColoursAreReadableTogether(): void
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (int $value): float {
                $value /= 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, BrandColours::rgb($hex));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $ratio = (1 + 0.05) / ($luminance(BrandColours::BACKGROUND) + 0.05);

        self::assertSame([31, 78, 121], BrandColours::rgb(BrandColours::BACKGROUND));
        self::assertSame([255, 255, 255], BrandColours::rgb(BrandColours::FOREGROUND));
        self::assertGreaterThanOrEqual(4.5, $ratio, 'the generated icon, share image and wordmark pass 4.5:1');
    }
}
