<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Modules;

use SightPress\Module\Dashboard\DashboardContext;
use SightPress\Module\Dashboard\DashboardWidget;

final class ExampleDashboardWidget implements DashboardWidget
{
    public function id(): string
    {
        return 'events.upcoming';
    }

    public function title(): string
    {
        return 'Upcoming events';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function position(): int
    {
        return 0;
    }

    public function render(DashboardContext $context): string
    {
        return '<p>No events.</p>';
    }
}
