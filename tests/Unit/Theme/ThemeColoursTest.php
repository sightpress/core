<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Tests\Support\ArraySiteSettings;
use SightPress\Core\Theme\ColorMode;
use SightPress\Core\Theme\ThemeColours;
use SightPress\Core\Theme\ThemeLoader;

#[CoversClass(ThemeColours::class)]
#[UsesNamespace('SightPress\Core\Theme')]
#[UsesNamespace('SightPress\Core\Template')]
final class ThemeColoursTest extends TestCase
{
    public function testStoresTheColoursOfEachTheme(): void
    {
        $settings = new ArraySiteSettings();
        $colours = new ThemeColours($settings);

        self::assertSame(['light' => [], 'dark' => []], $colours->overrides('default'));
        self::assertSame('', $colours->stored('default'));
        $colours->save('default', ['light' => ['primary' => '#004080'], 'dark' => []]);
        self::assertSame(['light' => ['primary' => '#004080'], 'dark' => []], $colours->overrides('default'));
        self::assertSame(['light' => [], 'dark' => []], $colours->overrides('acme-harbour'));
        self::assertNotSame('', $colours->stored('default'));
        $colours->save('default', ['light' => [], 'dark' => []]);
        self::assertSame('', $settings->get(ThemeColours::SETTING . 'default'));
    }

    public function testIgnoresWhatItCantUse(): void
    {
        $settings = new ArraySiteSettings([ThemeColours::SETTING . 'a' => '{', ThemeColours::SETTING . 'b' => '{"light": {"primary": "#123456", "surface": "#000000", "link": "blue", "text": 5}, "dark": "none"}']);
        $colours = new ThemeColours($settings);

        self::assertSame(['light' => [], 'dark' => []], $colours->overrides('a'));
        self::assertSame(['light' => ['primary' => '#123456'], 'dark' => []], $colours->overrides('b'));
    }

    public function testPutsTheColoursInPlaceOfTheThemesOwn(): void
    {
        $theme = new ThemeLoader()->load(TemplatePaths::defaultTheme());
        $changed = ThemeColours::apply($theme, ['light' => ['primary' => '#004080', 'chart-9' => '#000000'], 'dark' => ['link' => '#ffcc00', '' => '#000000']]);

        self::assertSame('#004080', $changed->tokens->color('primary', ColorMode::Light)?->hex);
        self::assertSame('#ffcc00', $changed->tokens->color('link', ColorMode::Dark)?->hex);
        self::assertNull($changed->tokens->color('chart-9', ColorMode::Light));
        self::assertSame($theme->tokens->color('text', ColorMode::Light)?->hex, $changed->tokens->color('text', ColorMode::Light)?->hex);
        self::assertSame([$theme->name, $theme->label, $theme->version, $theme->directory, $theme->contrast], [$changed->name, $changed->label, $changed->version, $changed->directory, $changed->contrast]);
    }
}
