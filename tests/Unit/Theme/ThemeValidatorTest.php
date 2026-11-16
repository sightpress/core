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
        self::assertSame(count($theme->contrast) * 4, $result->checks);
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
        self::assertSame(8, $result->checks);
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

    public function testAThemeWithoutPairsFails(): void
    {
        $result = new ThemeValidator()->validate(self::theme(pairs: []));

        self::assertSame(0, $result->checks);
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
    ): Theme {
        return new Theme('t', 'T', '1', '/themes/t', new ThemeTokens(
            self::colors($light),
            self::colors($dark === [] ? $light : $dark),
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
