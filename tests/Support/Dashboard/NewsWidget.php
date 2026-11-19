<?php

declare(strict_types=1);

namespace SightPress\Core\Tests\Support\Dashboard;

use RuntimeException;
use SightPress\Module\Dashboard\DashboardContext;
use SightPress\Module\Dashboard\DashboardWidget;

/**
 * A test widget for everyone, linking to a route with a placeholder.
 */
final readonly class NewsWidget implements DashboardWidget, \Psr\Http\Server\RequestHandlerInterface
{
    public function id(): string
    {
        return 'acme.news';
    }

    public function title(): string
    {
        return 'Latest news';
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
        return sprintf('<p>News for %s in %s, <a href="%s">read</a>.</p>', $context->locale(), $context->timeZone()->getName(), $context->url('acme.news.item', ['id' => '7']));
    }

    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        throw new RuntimeException('Not used.');
    }
}
