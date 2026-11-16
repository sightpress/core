<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Theme\ColorMode;

#[CoversClass(ColorMode::class)]
final class ColorModeTest extends TestCase
{
    public function testEachModeHasAPlainNameAndABaseMode(): void
    {
        $labels = [];
        $bases = [];
        foreach (ColorMode::cases() as $mode) {
            $labels[$mode->value] = $mode->label();
            $bases[$mode->value] = $mode->base();
        }

        self::assertSame([
            'light' => 'light mode',
            'dark' => 'dark mode',
            'light-more' => 'light mode with more contrast',
            'dark-more' => 'dark mode with more contrast',
        ], $labels);
        self::assertSame([
            'light' => ColorMode::Light,
            'dark' => ColorMode::Dark,
            'light-more' => ColorMode::Light,
            'dark-more' => ColorMode::Dark,
        ], $bases);
    }
}
