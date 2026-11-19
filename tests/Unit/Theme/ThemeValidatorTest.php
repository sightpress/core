<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Template\TemplatePaths;
use SightPress\Core\Theme\Color;
use SightPress\Core\Theme\ColorMode;
use SightPress\Core\Theme\ContrastPair;
use SightPress\Core\Theme\ContrastUse;
use SightPress\Core\Theme\Theme;
use SightPress\Core\Theme\ThemeIssue;
use SightPress\Core\Theme\ThemeLoader;
use SightPress\Core\Theme\ThemeTokens;
use SightPress\Core\Theme\ThemeValidation;
use SightPress\Core\Theme\ThemeValidator;

#[CoversClass(ThemeValidator::class)]
#[CoversClass(ThemeValidation::class)]
#[CoversClass(ThemeIssue::class)]
#[CoversClass(ContrastPair::class)]
#[UsesClass(Color::class)]
#[UsesClass(ColorMode::class)]
#[UsesClass(ContrastUse::class)]
#[UsesClass(Theme::class)]
#[UsesClass(ThemeTokens::class)]
#[UsesClass(ThemeLoader::class)]
#[UsesClass(TemplatePaths::class)]
final class ThemeValidatorTest extends TestCase
{
    public function testTheDefaultThemePassesEveryPairInEveryMode(): void
    {
        $theme = new ThemeLoader()->load(TemplatePaths::defaultTheme());
        $result = new ThemeValidator()->validate($theme);

        self::assertSame([], array_map(static fn (ThemeIssue $i): string => $i->message, $result->issues));
        self::assertTrue($result->passes());
        self::assertSame((count($theme->contrast) + 10) * 4, $result->checks, 'the declared pairs and the chart colours, in every mode');
        self::assertSame($theme, $result->theme);
    }

    public function testEveryFailingPairIsNamedWithItsModeColoursAndRatio(): void
    {
        $result = new ThemeValidator()->validate(self::theme(
            light: ['text' => '#767676', 'background' => '#ffffff', 'border' => '#959595'],
            dark: ['text' => '#ffffff', 'background' => '#000000', 'border' => '#ffffff'],
            darkMore: ['text' => '#222222'],
        ));

        self::assertFalse($result->passes());
        self::assertSame(8 + 20, $result->checks, 'chart colours on the background (the theme has no surface)');
        self::assertSame([
            'In light mode, interface colour "border" (#959595) on "background" (#ffffff) has a contrast of 2.99:1; it needs at least 3.00:1.',
            'In light mode with more contrast, interface colour "border" (#959595) on "background" (#ffffff) has a contrast of 2.99:1; it needs at least 3.00:1.',
            'In dark mode with more contrast, text "text" (#222222) on "background" (#000000) has a contrast of 1.31:1; it needs at least 4.50:1.',
        ], array_map(static fn (ThemeIssue $i): string => $i->message, $result->issues));
        self::assertSame(ColorMode::Light, $result->issues[0]->mode);
        self::assertSame('border', $result->issues[0]->pair?->foreground);
        self::assertSame(ContrastUse::Ui, $result->issues[0]->pair->use);
        self::assertEqualsWithDelta(2.99, $result->issues[0]->ratio, 0.01);
    }

    public function testARatioJustUnderTheMinimumNeverRoundsUpToAPass(): void
    {
        // #777777 on white is 4.478:1.
        $result = new ThemeValidator()->validate(self::theme(light: ['text' => '#777777', 'background' => '#ffffff', 'border' => '#000000']));

        self::assertStringContainsString('has a contrast of 4.47:1; it needs at least 4.50:1.', $result->issues[0]->message);
    }

    public function testChartColoursAreRequiredAndChecked(): void
    {
        $missing = new ThemeValidator()->validate(self::theme(light: ['text' => '#000000', 'background' => '#ffffff', 'border' => '#000000', 'chart-text' => '#000000'], charts: false));
        self::assertSame(
            'The theme has no chart colours "chart-1", "chart-2", "chart-3", "chart-4", "chart-grid". Add them to "colors.light" and "colors.dark" in theme.json: chart-1 to chart-4 for the lines and bars, chart-grid and chart-text.',
            $missing->issues[0]->message,
        );
        $one = new ThemeValidator()->validate(self::theme(light: ['text' => '#000000', 'background' => '#ffffff', 'border' => '#000000', 'chart-1' => '#000000', 'chart-2' => '#000000', 'chart-3' => '#000000', 'chart-4' => '#000000', 'chart-text' => '#000000'], charts: false));
        self::assertStringStartsWith('The theme has no chart colour "chart-grid". Add it to', $one->issues[0]->message);

        $faint = new ThemeValidator()->validate(self::theme(light: ['text' => '#000000', 'background' => '#ffffff', 'border' => '#000000', 'chart-2' => '#aaaaaa', 'chart-text' => '#888888']));
        self::assertSame([
            'In light mode, interface colour "chart-2" (#aaaaaa) on "background" (#ffffff) has a contrast of 2.32:1; it needs at least 3.00:1.',
            'In light mode, text "chart-text" (#888888) on "background" (#ffffff) has a contrast of 3.54:1; it needs at least 4.50:1.',
        ], array_slice(array_map(static fn (ThemeIssue $i): string => $i->message, $faint->issues), 0, 2));
    }

    public function testAThemeWithoutPairsFails(): void
    {
        $result = new ThemeValidator()->validate(self::theme(pairs: []));

        self::assertSame(20, $result->checks, 'only the chart colours');
        self::assertStringStartsWith('The theme declares no colour pairs under "contrast" in theme.json', $result->issues[0]->message);
        self::assertNull($result->issues[0]->pair);
        self::assertNull($result->issues[0]->mode);
        self::assertNull($result->issues[0]->ratio);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function fontSizes(): iterable
    {
        yield '1rem' => ['1rem', null];
        yield '1.125rem' => ['1.125rem', null];
        yield '16px' => ['16px', null];
        yield '.9rem' => ['.9rem', 'The base font size .9rem is 14.4 px; it must be at least 16 px (1rem).'];
        yield '15px' => ['15px', 'The base font size 15px is 15 px; it must be at least 16 px (1rem).'];
        yield 'em' => ['1em', 'The base font size "1em" can\'t be checked: write it in rem or px, for example 1.125rem.'];
        yield 'calc' => ['calc(1rem + 1px)', 'The base font size "calc(1rem + 1px)" can\'t be checked: write it in rem or px, for example 1.125rem.'];
    }

    #[DataProvider('fontSizes')]
    public function testTheBaseFontSizeIsAtLeastSixteenPixels(string $size, ?string $message): void
    {
        $result = new ThemeValidator()->validate(self::theme(size: $size));

        self::assertSame($message === null ? [] : [$message], array_map(static fn (ThemeIssue $i): string => $i->message, $result->issues));
    }

    /**
     * @param array<non-empty-string, string>  $light
     * @param array<non-empty-string, string>  $dark
     * @param array<non-empty-string, string>  $darkMore
     * @param list<ContrastPair>|null          $pairs
     */
    private static function theme(
        array $light = ['text' => '#000000', 'background' => '#ffffff', 'border' => '#000000'],
        array $dark = [],
        array $darkMore = [],
        ?array $pairs = null,
        string $size = '1rem',
        bool $charts = true,
    ): Theme {
        $chart = static fn (string $hex): array => $charts ? array_fill_keys([...ThemeValidator::CHART_MARKS, ThemeValidator::CHART_GRID, ThemeValidator::CHART_TEXT], $hex) : [];
        $light += $chart('#000000');
        $dark = $dark === [] ? $light : $dark + $chart('#ffffff');

        return new Theme('t', 'T', '1', '/themes/t', new ThemeTokens(
            self::colors($light),
            self::colors($dark),
            [],
            self::colors($darkMore),
            ['typography' => ['size-base' => $size]],
        ), $pairs ?? [
            new ContrastPair('text', 'background', ContrastUse::Text),
            new ContrastPair('border', 'background', ContrastUse::Ui),
            new ContrastPair('text', 'missing', ContrastUse::Text),
        ]);
    }

    /**
     * @param array<non-empty-string, string> $hex
     * @return array<non-empty-string, Color>
     */
    private static function colors(array $hex): array
    {
        return array_map(Color::fromHex(...), $hex);
    }
}
