<?php

declare(strict_types=1);

namespace Acme\Events;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Module\Http\PublicScreen;
use SightPress\Module\Http\Screens;

/**
 * `/events/{slug}`: the event's page in the site's theme, and `/events/{slug}.md`, its Markdown version.
 */
final readonly class PublicHandler implements RequestHandlerInterface
{
    public function __construct(
        private Screens $screens,
        private EventStore $events,
        private SeoEntries $seo,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $slug = $request->getAttribute('slug');
        $slug = is_string($slug) ? $slug : '';
        $markdown = str_ends_with($slug, '.md');
        $event = $this->events->find($markdown ? substr($slug, 0, -3) : $slug);
        if ($event === null) {
            return $this->screens->public($request, new PublicScreen('Event not found', '@acme_events/missing.html.twig', status: 404));
        }
        if ($markdown) {
            $response = $this->responses->createResponse()->withHeader('Content-Type', 'text/markdown; charset=utf-8');
            $response->getBody()->write(sprintf("# %s\n\nStarts at %s.\n", $event['title'], $event['starts_at']));

            return $response;
        }

        return $this->screens->public($request, new PublicScreen($event['title'], '@acme_events/event.html.twig', ['event' => $event], seo: $this->seo->entry($event)));
    }
}
