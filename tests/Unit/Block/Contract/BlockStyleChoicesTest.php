<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block\Contract;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Core\Template\Message;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockVariant;

#[CoversClass(BlockStyleChoices::class)]
#[UsesClass(Message::class)]
final class BlockStyleChoicesTest extends TestCase
{
    public function testListsTheVariantFirstThenTheOptions(): void
    {
        self::assertSame([
            'variant' => ['plain', 'card'],
            'align' => ['start', 'center', 'end'],
            'form_layout' => ['stacked', 'horizontal'],
        ], BlockStyleChoices::choices(self::styles()));
        self::assertSame([], BlockStyleChoices::choices(new BlockStyles()));
    }

    public function testKeepsOnlyPossibleChoicesThatAreNotTheDefault(): void
    {
        self::assertSame(
            ['variant' => 'card', 'align' => 'end'],
            BlockStyleChoices::clean(self::styles(), ['variant' => 'card', 'align' => 'end', 'form_layout' => 'stacked', 'width' => 'wide']),
        );
        self::assertSame([], BlockStyleChoices::clean(self::styles(), ['variant' => 'gone', 'align' => 3, 'form_layout' => 'inline']));
        self::assertSame([], BlockStyleChoices::clean(self::styles(), 'card'));
        self::assertSame([], BlockStyleChoices::clean(null, ['align' => 'end']));
    }

    public function testSaysWhatIsWrongWithTheChoices(): void
    {
        self::assertSame([], BlockStyleChoices::problems(self::styles(), null));
        self::assertSame([], BlockStyleChoices::problems(null, []));
        self::assertSame([], BlockStyleChoices::problems(self::styles(), ['variant' => 'card', 'align' => 'start']));

        $notChoices = BlockStyleChoices::problems(self::styles(), ['card']);
        self::assertSame([''], array_keys($notChoices));
        self::assertSame('The look of this block must be a set of choices, such as align: center.', $notChoices['']->text);
        self::assertSame([''], array_keys(BlockStyleChoices::problems(self::styles(), 'card')));

        $problems = BlockStyleChoices::problems(self::styles(), ['width' => 'wide', 'align' => 'justify', 'variant' => 1]);
        self::assertSame(['width', 'align', 'variant'], array_keys($problems));
        self::assertSame(['choice' => 'width'], $problems['width']->parameters);
        self::assertSame(['values' => 'start, center, end'], $problems['align']->parameters);
        self::assertSame(['values' => 'plain, card'], $problems['variant']->parameters);

        self::assertSame(['align'], array_keys(BlockStyleChoices::problems(null, ['align' => 'end'])));
    }

    public function testDrawsTheChoicesAsClassesOnAWrapper(): void
    {
        $choices = ['variant' => 'card', 'align' => 'center', 'background' => 'muted', 'width' => 'wide', 'spacing' => 'large', 'form_layout' => 'horizontal', 'form_size' => 'small', 'button_align' => 'stretch'];

        self::assertSame([
            'block-styled', 'block--variant-card', 'has-text-align-center', 'block--background-muted', 'block--width-wide',
            'block--spacing-large', 'form--layout-horizontal', 'form--size-small', 'form--button-stretch',
        ], BlockStyleChoices::classes($choices));
        self::assertSame([], BlockStyleChoices::classes([]));
        self::assertSame('<div class="block-styled has-text-align-end"><p>Hi</p></div>', BlockStyleChoices::wrap('<p>Hi</p>', ['align' => 'end']));
        self::assertSame('<p>Hi</p>', BlockStyleChoices::wrap('<p>Hi</p>', []));
        self::assertSame('', BlockStyleChoices::wrap('', ['align' => 'end']));
    }

    private static function styles(): BlockStyles
    {
        return new BlockStyles(
            [new BlockVariant('plain', 'Plain'), new BlockVariant('card', 'Card')],
            [BlockOption::Align, BlockOption::FormLayout],
        );
    }
}
