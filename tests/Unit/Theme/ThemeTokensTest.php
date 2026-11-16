<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\ColorMode;
use SightPress\Core\Theme\ThemeTokens;

#[CoversClass(ThemeTokens::class)]
#[UsesClass(Color::class)]
final class ThemeTokensTest extends TestCase
{
    public function testMoreContrastModesChangeOnlyTheColoursTheyList(): void
    {
        $tokens = new ThemeTokens(
            ['text' => Color::fromHex('#222'), 'background' => Color::fromHex('#fff')],
            ['text' => Color::fromHex('#eee'), 'background' => Color::fromHex('#111')],
            ['text' => Color::fromHex('#000')],
            ['background' => Color::fromHex('#000')],
            ['typography' => ['size-base' => '1.125rem']],
        );

        $hex = static fn (ColorMode $mode): array => array_map(static fn (Color $c): string => $c->hex, $tokens->colors($mode));
        self::assertSame(['text' => '#222222', 'background' => '#ffffff'], $hex(ColorMode::Light));
        self::assertSame(['text' => '#eeeeee', 'background' => '#111111'], $hex(ColorMode::Dark));
        self::assertSame(['text' => '#000000', 'background' => '#ffffff'], $hex(ColorMode::LightMore));
        self::assertSame(['text' => '#eeeeee', 'background' => '#000000'], $hex(ColorMode::DarkMore));
        self::assertSame('#000000', $tokens->color('background', ColorMode::DarkMore)?->hex);
        self::assertNull($tokens->color('link', ColorMode::Light));
        self::assertSame('1.125rem', $tokens->value('typography', 'size-base'));
        self::assertNull($tokens->value('spacing', '1'));
    }
}
