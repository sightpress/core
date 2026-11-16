<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support;

use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A block type with one field per kind of media file, to test that the validator checks the kind.
 */
final class MediaKindsBlockType implements BlockType
{
    public function name(): string
    {
        return 'acme.media_kinds';
    }

    public function label(): string
    {
        return 'Media kinds';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [
            'video' => ['type' => 'integer', 'format' => 'media-video'],
            'audio' => ['type' => 'integer', 'format' => 'media-audio'],
            'document' => ['type' => 'integer', 'format' => 'media-document'],
        ]];
    }

    public function upgradeData(int $fromVersion, array $data): array
    {
        return $data;
    }

    public function render(array $data, BlockContext $context): string
    {
        return '';
    }

    public function renderMarkdown(array $data, BlockContext $context): string
    {
        return '';
    }

    public function structuredData(array $data, BlockContext $context): ?array
    {
        return null;
    }

    public function a11yRules(): array
    {
        return [];
    }
}
