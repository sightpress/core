<?php

declare(strict_types=1);

namespace SightPress\Core\Admin\Http;

use DateTimeZone;
use SightPress\Core\Infrastructure\Dashboard\DashboardWidgets;
use SightPress\Core\Rbac\Contract\Actor;
use SightPress\Core\Template\Viewer;
use SightPress\Module\Dashboard\DashboardContext;

/**
 * Who is looking at the dashboard, for its widgets: their admin language and time zone, their permissions, and the
 * paths of named routes.
 */
final readonly class AdminDashboardContext implements DashboardContext
{
    public function __construct(
        private Viewer $viewer,
        private Actor $actor,
        private DashboardWidgets $widgets,
    ) {
    }

    public function locale(): string
    {
        return $this->viewer->locale;
    }

    public function timeZone(): DateTimeZone
    {
        return new DateTimeZone($this->viewer->timezone);
    }

    public function can(string $permission): bool
    {
        return $this->actor->has($permission);
    }

    public function url(string $route, array $parameters = []): string
    {
        return $this->widgets->url($route, $parameters);
    }
}
