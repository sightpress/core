<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Dashboard;

use SightPress\Module\Dashboard\DashboardContext;
use SightPress\Module\Dashboard\DashboardWidget;

/**
 * A test widget for people with `acme.money`.
 */
final readonly class MoneyWidget implements DashboardWidget
{
    public function id(): string
    {
        return 'acme.money';
    }

    public function title(): string
    {
        return 'Money';
    }

    public function permission(): string
    {
        return 'acme.money';
    }

    public function position(): int
    {
        return 10;
    }

    public function render(DashboardContext $context): string
    {
        return '<p>' . ($context->can('acme.money') ? 'Rich' : 'Poor') . '</p>';
    }
}
