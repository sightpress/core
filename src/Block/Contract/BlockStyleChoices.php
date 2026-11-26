<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Core\Template\Message;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;

/**
 * The looks and shared options an editor chose for one block (blocks.md §2–§4, ADR-0064), stored next to its data as
 * `"styles": {"variant": "card", "align": "center"}`. Only choices that differ from the default are stored, so a block
 * without `styles` looks the way it always did. Core draws them as classes on a wrapper around the block's HTML, never
 * as inline styles, and the theme styles the classes through its tokens.
 */
final class BlockStyleChoices
{
    /** The key of the variant among the choices; the others are {@see BlockOption} values. */
    public const string VARIANT = 'variant';

    /** Class of the wrapper drawn around a block with choices. */
    public const string WRAPPER = 'block-styled';

    /**
     * What an editor can choose for the block type, in the order the editor shows them: the variant first.
     *
     * @return array<string, non-empty-list<string>> key => values, the default first
     */
    public static function choices(BlockStyles $styles): array
    {
        $choices = [];
        $variants = array_map(static fn ($variant): string => $variant->name, $styles->variants);
        if ($variants !== []) {
            $choices[self::VARIANT] = $variants;
        }
        foreach ($styles->options as $option) {
            $values = $styles->values($option);
            if ($values !== []) {
                $choices[$option->value] = $values;
            }
        }

        return $choices;
    }

    /**
     * The stored choices that are still possible, without the defaults: a variant of a module that is now off, or
     * an option the type no longer offers, falls back to the default.
     *
     * @return array<string, string>
     */
    public static function clean(?BlockStyles $styles, mixed $stored): array
    {
        if ($styles === null || !is_array($stored)) {
            return [];
        }
        $clean = [];
        foreach (self::choices($styles) as $key => $values) {
            $value = $stored[$key] ?? null;
            if (is_string($value) && $value !== $values[0] && in_array($value, $values, true)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * What is wrong with the stored choices, by key (`''` when they aren't a set of choices at all).
     *
     * @return array<string, Message>
     */
    public static function problems(?BlockStyles $styles, mixed $stored): array
    {
        if ($stored === null || $stored === []) {
            return [];
        }
        if (!is_array($stored) || array_is_list($stored)) {
            return ['' => new Message('The look of this block must be a set of choices, such as align: center.')];
        }
        $choices = $styles === null ? [] : self::choices($styles);
        $problems = [];
        foreach ($stored as $key => $value) {
            $key = (string) $key;
            if (!isset($choices[$key])) {
                $problems[$key] = new Message('This block has no "{choice}" choice.', ['choice' => $key]);
            } elseif (!is_string($value) || !in_array($value, $choices[$key], true)) {
                $problems[$key] = new Message('Choose one of: {values}.', ['values' => implode(', ', $choices[$key])]);
            }
        }

        return $problems;
    }

    /**
     * The classes of the wrapper, or none when every choice is the default.
     *
     * @param array<string, string> $choices from {@see clean()}
     *
     * @return list<string>
     */
    public static function classes(array $choices): array
    {
        if ($choices === []) {
            return [];
        }
        $classes = [self::WRAPPER];
        foreach ($choices as $key => $value) {
            $classes[] = self::prefix($key) . $value;
        }

        return $classes;
    }

    /**
     * The block's HTML in a wrapper with its classes, or unchanged when every choice is the default.
     *
     * @param array<string, string> $choices from {@see clean()}
     */
    public static function wrap(string $html, array $choices): string
    {
        $classes = self::classes($choices);
        if ($classes === [] || $html === '') {
            return $html;
        }

        return '<div class="' . implode(' ', $classes) . '">' . $html . '</div>';
    }

    private static function prefix(string $key): string
    {
        return match ($key) {
            self::VARIANT => 'block--variant-',
            BlockOption::Align->value => 'has-text-align-',
            BlockOption::FormLayout->value => 'form--layout-',
            BlockOption::FormSize->value => 'form--size-',
            BlockOption::ButtonAlign->value => 'form--button-',
            default => 'block--' . $key . '-',
        };
    }
}
