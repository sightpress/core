<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Block\BlockContext;
use SightPress\Module\Block\BlockType;

/**
 * A block type for registrar tests.
 */
final class ExampleBlockType implements BlockType
{
    public function name(): string
    {
        return 'acme.example';
    }

    public function label(): string
    {
        return 'Example';
    }

    public function version(): int
    {
        return 1;
    }

    public function schema(): array
    {
        return [];
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
