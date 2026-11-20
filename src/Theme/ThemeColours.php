<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

use JsonException;
use SightPress\Core\Site\Contract\SiteSettings;

/**
 * A site's own colours on top of its theme's (T-2): the main colours of light and dark mode, stored per theme in the
 * site setting `theme.colours.<theme id>` as `{"light": {"primary": "#0a57c2"}, "dark": {…}}`. They replace the
 * theme's colours wherever the theme is shown on the site (the generated `tokens.css`); "more contrast" keeps the
 * theme's own overrides. They are checked for contrast like the theme itself before they are saved.
 */
final readonly class ThemeColours
{
    public const string SETTING = 'theme.colours.';

    /** The colours a site may change: the ones every theme defines. */
    public const array EDITABLE = ['background', 'text', 'link', 'primary', 'on-primary', 'border', 'focus'];

    public const array MODES = ['light', 'dark'];

    public function __construct(private SiteSettings $settings)
    {
    }

    /**
     * The site's colours for a theme.
     *
     * @return array{light: array<string, string>, dark: array<string, string>} hex by colour name, per mode
     */
    public function overrides(string $themeId): array
    {
        try {
            $data = json_decode($this->settings->get(self::SETTING . $themeId) ?? '{}', true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = [];
        }
        $overrides = ['light' => [], 'dark' => []];
        foreach (self::MODES as $mode) {
            $colours = is_array($data) && is_array($data[$mode] ?? null) ? $data[$mode] : [];
            foreach ($colours as $name => $hex) {
                if (in_array($name, self::EDITABLE, true) && is_string($hex) && preg_match('/^#[0-9a-f]{6}$/', $hex) === 1) {
                    $overrides[$mode][$name] = $hex;
                }
            }
        }

        return $overrides;
    }

    /**
     * Raw stored value, for the asset version: changes whenever the colours do.
     */
    public function stored(string $themeId): string
    {
        return $this->settings->get(self::SETTING . $themeId) ?? '';
    }

    /**
     * @param array{light: array<string, string>, dark: array<string, string>} $overrides only colours that differ
     *                                                                                  from the theme's
     */
    public function save(string $themeId, array $overrides): void
    {
        $this->settings->set(self::SETTING . $themeId, $overrides === ['light' => [], 'dark' => []] ? '' : (string) json_encode($overrides));
    }

    /**
     * The theme with the site's colours in place of its own.
     *
     * @param array{light: array<string, string>, dark: array<string, string>} $overrides
     */
    public static function apply(Theme $theme, array $overrides): Theme
    {
        $light = $theme->tokens->light;
        $dark = $theme->tokens->dark;
        foreach ($overrides['light'] as $name => $hex) {
            if ($name !== '' && isset($light[$name])) {
                $light[$name] = Color::fromHex($hex);
            }
        }
        foreach ($overrides['dark'] as $name => $hex) {
            if ($name !== '' && isset($dark[$name])) {
                $dark[$name] = Color::fromHex($hex);
            }
        }

        return new Theme(
            $theme->name,
            $theme->label,
            $theme->version,
            $theme->directory,
            new ThemeTokens($light, $dark, $theme->tokens->lightMore, $theme->tokens->darkMore, $theme->tokens->values),
            $theme->contrast,
        );
    }
}
