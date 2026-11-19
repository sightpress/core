<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Dashboard;

use RuntimeException;
use SightPress\Module\Dashboard\DashboardContext;
use SightPress\Module\Dashboard\DashboardWidget;

/**
 * A test widget that always fails.
 */
final readonly class BrokenWidget implements DashboardWidget
{
    public function id(): string
    {
        return 'acme.broken';
    }

    public function title(): string
    {
        return 'Broken';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function position(): int
    {
        return 20;
    }

    public function render(DashboardContext $context): string
    {
        throw new RuntimeException('No news today.');
    }
}
