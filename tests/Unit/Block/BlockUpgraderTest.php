<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\BlockUpgrader;
use SightPress\Core\Tests\Support\VersionedBlockType;
use PHPUnit\Framework\Attributes\UsesClass;
use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Module\Block\BlockOption;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockVariant;

#[CoversClass(BlockUpgrader::class)]
#[UsesClass(BlockStyleChoices::class)]
final class BlockUpgraderTest extends TestCase
{
    public function testUpgradesOldDataAndRefusesNewerData(): void
    {
        $type = new VersionedBlockType();

        self::assertSame(['label' => 'Hi'], BlockUpgrader::upgrade($type, 2, ['label' => 'Hi']));
        self::assertSame(['label' => 'Old'], BlockUpgrader::upgrade($type, 1, ['text' => 'Old']));
        self::assertSame(['label' => 'Zero'], BlockUpgrader::upgrade($type, 0, ['text' => 'Zero']));
        self::assertNull(BlockUpgrader::upgrade($type, 3, ['label' => 'Future']));
    }

    public function testBringsTheLooksToWhatTheTypeOffersNow(): void
    {
        $styles = new BlockStyles([new BlockVariant('plain', 'Plain'), new BlockVariant('card', 'Card')], [BlockOption::Align]);

        self::assertSame([], BlockUpgrader::styles($styles, null));
        self::assertSame(['variant' => 'card'], BlockUpgrader::styles($styles, ['variant' => 'card', 'width' => 'wide']));
        self::assertSame([], BlockUpgrader::styles($styles, ['variant' => 'from-a-module-that-is-off']));
        self::assertSame([], BlockUpgrader::styles(null, ['align' => 'end']));
    }
}
