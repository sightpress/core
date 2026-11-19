<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * Checks a theme can be used (WCAG 2.2 AA): every foreground / background pair it declares has enough contrast
 * (4.5:1 for text, 3:1 for interface colours) in light and dark mode, each with and without more contrast, and the
 * base font size is at least 16 px. A theme that declares no pairs fails, because nothing could be checked. Chart
 * colours are always checked, whether the theme lists them or not: chart lines and bars 3:1 and chart text 4.5:1 on
 * the background and the surface (AN-2); a theme without them fails.
 *
 * Ratios are compared unrounded, so 4.49:1 never passes as 4.5:1; messages show them rounded down.
 */
final readonly class ThemeValidator
{
    public const float MINIMUM_FONT_PX = 16.0;

    /** Colours of chart lines and bars (AN-2): each needs 3:1 on the background and the surface. */
    public const array CHART_MARKS = ['chart-1', 'chart-2', 'chart-3', 'chart-4'];

    /** Colour of chart gridlines; decorative, so it has no contrast rule. */
    public const string CHART_GRID = 'chart-grid';

    /** Colour of the text in charts: 4.5:1 on the background and the surface. */
    public const string CHART_TEXT = 'chart-text';

    public function validate(Theme $theme): ThemeValidation
    {
        $issues = [];
        $checks = 0;
        if ($theme->contrast === []) {
            $issues[] = new ThemeIssue(
                'The theme declares no colour pairs under "contrast" in theme.json, so its contrast can\'t be checked. '
                . 'List each text and interface colour with the background it is used on.',
            );
        }

        $missing = array_values(array_filter(
            [...self::CHART_MARKS, self::CHART_GRID, self::CHART_TEXT],
            static fn (string $name): bool => $theme->tokens->color($name, ColorMode::Light) === null,
        ));
        if ($missing !== []) {
            $issues[] = new ThemeIssue(sprintf(
                'The theme has no chart colour%s "%s". Add %s to "colors.light" and "colors.dark" in theme.json: chart-1 to chart-4 for the lines and bars, chart-grid and chart-text.',
                count($missing) === 1 ? '' : 's',
                implode('", "', $missing),
                count($missing) === 1 ? 'it' : 'them',
            ));
        }
        $charts = [];
        foreach (['background', 'surface'] as $background) {
            foreach (self::CHART_MARKS as $mark) {
                $charts[] = new ContrastPair($mark, $background, ContrastUse::Ui);
            }
            $charts[] = new ContrastPair(self::CHART_TEXT, $background, ContrastUse::Text);
        }

        foreach (ColorMode::cases() as $mode) {
            foreach ([...$theme->contrast, ...$charts] as $pair) {
                $foreground = $theme->tokens->color($pair->foreground, $mode);
                $background = $theme->tokens->color($pair->background, $mode);
                if ($foreground === null || $background === null) {
                    continue;
                }
                $checks++;
                $ratio = $foreground->contrastWith($background);
                if ($ratio < $pair->use->minimum()) {
                    $issues[] = new ThemeIssue(sprintf(
                        'In %s, %s "%s" (%s) on "%s" (%s) has a contrast of %s:1; it needs at least %s:1.',
                        $mode->label(),
                        $pair->use->label(),
                        $pair->foreground,
                        $foreground->hex,
                        $pair->background,
                        $background->hex,
                        self::ratio($ratio),
                        self::ratio($pair->use->minimum()),
                    ), $pair, $mode, $ratio);
                }
            }
        }

        $size = (string) $theme->tokens->value('typography', 'size-base');
        $pixels = self::pixels($size);
        if ($pixels === null) {
            $issues[] = new ThemeIssue(sprintf(
                'The base font size "%s" can\'t be checked: write it in rem or px, for example 1.125rem.',
                $size,
            ));
        } elseif ($pixels < self::MINIMUM_FONT_PX) {
            $issues[] = new ThemeIssue(sprintf(
                'The base font size %s is %s px; it must be at least 16 px (1rem).',
                $size,
                rtrim(rtrim(number_format($pixels, 2, '.', ''), '0'), '.'),
            ));
        }

        return new ThemeValidation($theme, $issues, $checks);
    }

    /**
     * The ratio rounded down to two decimals, so a failing ratio never looks like a passing one.
     */
    private static function ratio(float $ratio): string
    {
        return number_format(floor($ratio * 100) / 100, 2, '.', '');
    }

    private static function pixels(string $size): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?|\.\d+)(rem|px)$/', trim($size), $match) !== 1) {
            return null;
        }

        return (float) $match[1] * ($match[2] === 'rem' ? 16 : 1);
    }
}
