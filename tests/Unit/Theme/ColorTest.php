<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\ThemeException;

#[CoversClass(Color::class)]
final class ColorTest extends TestCase
{
    public function testLongAndShortHexAreStoredAsLowerCaseLongHex(): void
    {
        self::assertSame('#1a1a1f', Color::fromHex('#1A1A1F')->hex);
        self::assertSame('#ffaa00', Color::fromHex('#fa0')->hex);
    }

    public function testLuminanceAndContrastFollowWcag(): void
    {
        self::assertSame(0.0, Color::fromHex('#000')->luminance());
        self::assertEqualsWithDelta(1.0, Color::fromHex('#fff')->luminance(), 1e-9);
        self::assertEqualsWithDelta(21.0, Color::fromHex('#000')->contrastWith(Color::fromHex('#fff')), 1e-9);
        self::assertEqualsWithDelta(21.0, Color::fromHex('#fff')->contrastWith(Color::fromHex('#000')), 1e-9);
        self::assertEqualsWithDelta(1.0, Color::fromHex('#777')->contrastWith(Color::fromHex('#777')), 1e-9);
        // Reference values: #767676 on white is the classic 4.54:1; a channel below the 0.04045 knee is linear.
        self::assertEqualsWithDelta(4.54, Color::fromHex('#767676')->contrastWith(Color::fromHex('#fff')), 0.005);
        self::assertEqualsWithDelta(0.0006, Color::fromHex('#0a0000')->luminance(), 0.0001);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notColours(): iterable
    {
        yield 'no hash' => ['1a1a1f'];
        yield 'named' => ['red'];
        yield 'four digits' => ['#abcd'];
        yield 'alpha' => ['#11223344'];
        yield 'css function' => ['rgb(0 0 0)'];
        yield 'injection' => ['#000; } body { display: none'];
    }

    #[DataProvider('notColours')]
    public function testAnythingElseIsRefusedWithAnExample(string $value): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage(sprintf('"%s" is not a colour: write it as #rgb or #rrggbb, for example #1a1a1f.', $value));

        Color::fromHex($value);
    }
}
