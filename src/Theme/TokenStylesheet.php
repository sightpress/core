<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * Turns a theme's tokens into the CSS custom properties its stylesheet uses: light mode on `:root`, then dark mode
 * (`prefers-color-scheme: dark`), more contrast (`prefers-contrast: more`) and both together.
 */
final readonly class TokenStylesheet
{
    public function compile(ThemeTokens $tokens): string
    {
        $root = ['color-scheme' => 'light dark'];
        foreach ($tokens->light as $name => $color) {
            $root['--color-' . $name] = $color->hex;
        }
        foreach (ThemeTokens::GROUPS as $group => $prefix) {
            foreach ($tokens->values[$group] ?? [] as $name => $value) {
                $root['--' . $prefix . '-' . $name] = $value;
            }
        }

        $css = "/* Generated from theme.json. */\n" . self::rule(':root', $root);
        $css .= self::media('(prefers-color-scheme: dark)', self::colors($tokens->dark));
        $css .= self::media('(prefers-contrast: more)', self::colors($tokens->lightMore));

        // In dark mode with more contrast, the block above also applies, so every colour it changes is set again.
        $darkMore = [];
        foreach (array_keys($tokens->lightMore + $tokens->darkMore) as $name) {
            $darkMore[$name] = $tokens->darkMore[$name] ?? $tokens->dark[$name];
        }

        return $css . self::media('(prefers-contrast: more) and (prefers-color-scheme: dark)', self::colors($darkMore));
    }

    /**
     * @param array<string, Color> $colors
     * @return array<string, string>
     */
    private static function colors(array $colors): array
    {
        $properties = [];
        foreach ($colors as $name => $color) {
            $properties['--color-' . $name] = $color->hex;
        }

        return $properties;
    }

    /**
     * @param array<string, string> $properties
     */
    private static function media(string $query, array $properties): string
    {
        if ($properties === []) {
            return '';
        }

        return sprintf("@media %s {\n%s}\n", $query, preg_replace('/^/m', '  ', self::rule(':root', $properties)));
    }

    /**
     * @param array<string, string> $properties
     */
    private static function rule(string $selector, array $properties): string
    {
        $css = $selector . " {\n";
        foreach ($properties as $property => $value) {
            $css .= sprintf("  %s: %s;\n", $property, $value);
        }

        return $css . "}\n";
    }
}
