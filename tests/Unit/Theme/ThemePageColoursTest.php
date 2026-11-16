<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemePageColours;

#[CoversClass(ThemePageColours::class)]
#[UsesNamespace('SightPress\Core\Theme')]
#[UsesNamespace('SightPress\Core\Template')]
final class ThemePageColoursTest extends TestCase
{
    public function testGivesTheTextAndBackgroundOfEveryColourMode(): void
    {
        $colours = new ThemePageColours(new ThemeLoader()->load(TemplatePaths::defaultTheme()))->pageColours();

        self::assertSame(['light mode', 'dark mode', 'light mode with more contrast', 'dark mode with more contrast'], array_keys($colours));
        self::assertSame(['text' => '#1a1a1f', 'background' => '#ffffff'], $colours['light mode']);
        self::assertSame(['text' => '#ffffff', 'background' => '#000000'], $colours['dark mode with more contrast']);
    }
}
