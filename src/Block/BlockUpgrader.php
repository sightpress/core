<?php

declare(strict_types=1);

namespace SightPress\Core\Block;

use SightPress\Core\Block\Contract\BlockStyleChoices;
use SightPress\Module\Block\BlockStyles;
use SightPress\Module\Block\BlockType;

/**
 * Brings block data stored at an older version of its type up to the current one, and the block's looks to what the
 * type offers now. Blocks stored before looks existed have no `styles`, so they get the defaults and look the same.
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

    /**
     * The block's stored looks that the type still offers on this site: a variant of a module that is off, or an
     * option the type dropped, falls back to its default.
     *
     * @return array<string, string>
     */
    public static function styles(?BlockStyles $styles, mixed $stored): array
    {
        return BlockStyleChoices::clean($styles, $stored);
    }
}
