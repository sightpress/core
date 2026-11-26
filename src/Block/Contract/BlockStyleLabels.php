<?php

declare(strict_types=1);

namespace SightPress\Core\Block\Contract;

use SightPress\Core\Template\Message;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;

/**
 * What the page editor calls a block's looks and their values, in en-GB (the editor translates them). Variants are
 * named by the block type or module that adds them.
 */
final class BlockStyleLabels
{
    public static function choice(string $key): Message
    {
        return match ($key) {
            BlockStyleChoices::VARIANT => Message::of('Style'),
            BlockOption::Align->value => Message::of('Text alignment'),
            BlockOption::Background->value => Message::of('Background'),
            BlockOption::Width->value => Message::of('Width'),
            BlockOption::Spacing->value => Message::of('Space above and below'),
            BlockOption::FormLayout->value => Message::of('Form layout'),
            BlockOption::FormSize->value => Message::of('Form size'),
            BlockOption::ButtonAlign->value => Message::of('Button position'),
            default => Message::of($key),
        };
    }

    /**
     * The hint under a choice, when it needs one.
     */
    public static function hint(string $key): ?Message
    {
        return match ($key) {
            BlockOption::Align->value => Message::of('Centred text is harder to read over more than a few lines.'),
            BlockOption::Background->value => Message::of('Colours come from the theme, so text on them always has enough contrast.'),
            BlockOption::FormLayout->value => Message::of('Labels beside the fields go back above them on narrow screens and when zoomed in.'),
            default => null,
        };
    }

    /**
     * The values of a choice, labelled, the default first.
     *
     * @return array<string, Message|string> value => label
     */
    public static function values(BlockStyles $styles, string $key): array
    {
        $labels = [];
        if ($key === BlockStyleChoices::VARIANT) {
            foreach ($styles->variants as $variant) {
                $labels[$variant->name] = $variant->label;
            }

            return $labels;
        }
        foreach (BlockStyleChoices::choices($styles)[$key] ?? [] as $value) {
            $labels[$value] = self::value($value);
        }

        return $labels;
    }

    /**
     * The label of one value of a shared option.
     */
    public static function value(string $value): Message
    {
        return match ($value) {
            'start' => Message::of('Start (left in left-to-right languages)'),
            'center' => Message::of('Centre'),
            'end' => Message::of('End (right in left-to-right languages)'),
            'transparent' => Message::of('None (what is behind it)'),
            'surface' => Message::of('Surface'),
            'muted' => Message::of('Muted'),
            'accent' => Message::of('Accent'),
            'content' => Message::of('Text width'),
            'wide' => Message::of('Wide'),
            'full' => Message::of('Full width'),
            'none' => Message::of('None'),
            'small' => Message::of('Small'),
            'medium' => Message::of('Medium'),
            'large' => Message::of('Large'),
            'stacked' => Message::of('Labels above the fields'),
            'horizontal' => Message::of('Labels beside the fields'),
            'inline' => Message::of('All in one row'),
            'stretch' => Message::of('Full width'),
            default => Message::of($value),
        };
    }
}
