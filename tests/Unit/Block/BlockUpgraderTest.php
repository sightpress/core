<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Unit\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SightPress\Core\Block\BlockUpgrader;
use SightPress\Core\Tests\Support\VersionedBlockType;

#[CoversClass(BlockUpgrader::class)]
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
}
