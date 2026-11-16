<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * Checks a theme can be used (WCAG 2.2 AA): every foreground / background pair it declares has enough contrast
 * (4.5:1 for text, 3:1 for interface colours) in light and dark mode, each with and without more contrast, and the
 * base font size is at least 16 px. A theme that declares no pairs fails, because nothing could be checked.
 *
 * Ratios are compared unrounded, so 4.49:1 never passes as 4.5:1; messages show them rounded down.
 */
final readonly class ThemeValidator
{
    public const float MINIMUM_FONT_PX = 16.0;

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

        foreach (ColorMode::cases() as $mode) {
            foreach ($theme->contrast as $pair) {
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
