<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Module\Block\BlockType;

/**
 * Brings block data stored at an older version of its type up to the current one.
 */
final class BlockUpgrader
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null the data at the current version, or null when it comes from a newer one
     */
    public static function upgrade(BlockType $type, int $version, array $data): ?array
    {
        if ($version > $type->version()) {
            return null;
        }

        return $version < $type->version() ? $type->upgradeData(max(1, $version), $data) : $data;
    }
}
