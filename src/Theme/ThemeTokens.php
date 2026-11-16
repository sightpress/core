<?php

declare(strict_types=1);

namespace SightPress\Core\Theme;

/**
 * The design tokens of a theme: colours for each {@see ColorMode}, and the typography, spacing, radius and focus
 * ring values. They become CSS custom properties ({@see TokenStylesheet}): `--color-<name>`, `--font-<name>`,
 * `--space-<name>`, `--radius-<name>` and `--focus-<name>`.
 */
final readonly class ThemeTokens
{
    /** Token groups other than colours, with the prefix of their CSS custom properties. */
    public const array GROUPS = [
        'typography' => 'font',
        'spacing' => 'space',
        'radius' => 'radius',
        'focus' => 'focus',
    ];

    /**
     * @param array<non-empty-string, Color>                                $light     every colour, light mode
     * @param array<non-empty-string, Color>                                $dark      the same colours, dark mode
     * @param array<non-empty-string, Color>                                $lightMore colours light mode changes
     *                                                                                 with more contrast
     * @param array<non-empty-string, Color>                                $darkMore  colours dark mode changes
     *                                                                                 with more contrast
     * @param array<key-of<self::GROUPS>, array<array-key, string>>        $values    other tokens by group
     */
    public function __construct(
        public array $light,
        public array $dark,
        public array $lightMore = [],
        public array $darkMore = [],
        public array $values = [],
    ) {
    }

    /**
     * Every colour as it is in the given mode.
     *
     * @return array<non-empty-string, Color>
     */
    public function colors(ColorMode $mode): array
    {
        return match ($mode) {
            ColorMode::Light => $this->light,
            ColorMode::Dark => $this->dark,
            ColorMode::LightMore => array_replace($this->light, $this->lightMore),
            ColorMode::DarkMore => array_replace($this->dark, $this->darkMore),
        };
    }

    public function color(string $name, ColorMode $mode): ?Color
    {
        return $this->colors($mode)[$name] ?? null;
    }

    /**
     * @param key-of<self::GROUPS> $group
     */
    public function value(string $group, string $name): ?string
    {
        return $this->values[$group][$name] ?? null;
    }
}
