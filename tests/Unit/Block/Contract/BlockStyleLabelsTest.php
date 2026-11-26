<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Core\Block\Contract\BlockStyleLabels;
use SightPress\Core\Template\Message;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockVariant;

#[CoversClass(BlockStyleLabels::class)]
#[UsesClass(BlockStyleChoices::class)]
#[UsesClass(Message::class)]
final class BlockStyleLabelsTest extends TestCase
{
    public function testNamesEveryChoice(): void
    {
        $names = [];
        foreach (['variant', ...array_map(static fn (BlockOption $option): string => $option->value, BlockOption::cases()), 'other'] as $key) {
            $names[$key] = BlockStyleLabels::choice($key)->text;
        }

        self::assertSame([
            'variant' => 'Style', 'align' => 'Text alignment', 'background' => 'Background', 'width' => 'Width',
            'spacing' => 'Space above and below', 'form_layout' => 'Form layout', 'form_size' => 'Form size',
            'button_align' => 'Button position', 'other' => 'other',
        ], $names);
    }

    public function testHintsWhereAChoiceNeedsOne(): void
    {
        self::assertSame('Centred text is harder to read over more than a few lines.', BlockStyleLabels::hint('align')?->text);
        self::assertNotNull(BlockStyleLabels::hint('background'));
        self::assertNotNull(BlockStyleLabels::hint('form_layout'));
        self::assertNull(BlockStyleLabels::hint('width'));
    }

    public function testLabelsTheValuesTheDefaultFirst(): void
    {
        $styles = new BlockStyles(
            [new BlockVariant('plain', 'Plain'), new BlockVariant('card', 'Card')],
            BlockOption::cases(),
            shortForm: true,
        );

        self::assertSame(['plain' => 'Plain', 'card' => 'Card'], BlockStyleLabels::values($styles, 'variant'));
        $texts = [];
        foreach (BlockOption::cases() as $option) {
            foreach (BlockStyleLabels::values($styles, $option->value) as $value => $label) {
                self::assertInstanceOf(Message::class, $label);
                $texts[$option->value . ':' . $value] = $label->text;
            }
        }
        self::assertSame('Start (left in left-to-right languages)', $texts['align:start']);
        self::assertSame('Centre', $texts['align:center']);
        self::assertSame('End (right in left-to-right languages)', $texts['align:end']);
        self::assertSame('None (what is behind it)', $texts['background:transparent']);
        self::assertSame(['Surface', 'Muted', 'Accent'], [$texts['background:surface'], $texts['background:muted'], $texts['background:accent']]);
        self::assertSame(['Text width', 'Wide', 'Full width'], [$texts['width:content'], $texts['width:wide'], $texts['width:full']]);
        self::assertSame(['Medium', 'None', 'Small', 'Large'], [$texts['spacing:medium'], $texts['spacing:none'], $texts['spacing:small'], $texts['spacing:large']]);
        self::assertSame(['Labels above the fields', 'Labels beside the fields', 'All in one row'], [$texts['form_layout:stacked'], $texts['form_layout:horizontal'], $texts['form_layout:inline']]);
        self::assertSame('Full width', $texts['button_align:stretch']);
        self::assertSame([], BlockStyleLabels::values(new BlockStyles(), 'align'));
        self::assertSame('other', BlockStyleLabels::value('other')->text);
    }
}
