<?php

declare(strict_types=1);

namespace Acme\Events;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SightPress\Module\Access\Viewer;
use SightPress\Module\Audit\AuditRecord;
use SightPress\Module\Audit\AuditTrail;
use SightPress\Module\Http\AdminScreen;
use SightPress\Module\Http\Screens;
use SightPress\Module\Setting\SettingValues;
use SightPress\Module\Text\Message;

final readonly class AdminHandler implements RequestHandlerInterface
{
    public function __construct(
        private Screens $screens,
        private EventStore $events,
        private SettingValues $settings,
        private AuditTrail $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $errors = [];
        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
            $startsAt = is_string($body['starts_at'] ?? null) ? trim($body['starts_at']) : '';
            if ($title === '') {
                $errors['title'] = 'Enter the event’s name.';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $startsAt) !== 1) {
                $errors['starts_at'] = 'Enter when it starts, for example 2026-12-01T20:00.';
            }
            if ($errors === []) {
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
                $this->events->add($slug, $title, $startsAt);
                $this->audit->record(new AuditRecord('acme.events.event_added', sprintf('Added the event “%s”.', $title), Viewer::fromRequest($request)->userId, 'event', $slug));

                return $this->screens->redirect($request, '/admin/events', new Message('Added “{title}”.', ['title' => $title], 'acme_events'));
            }
        }

        return $this->screens->admin($request, new AdminScreen(new Message('Events', domain: 'acme_events'), '@acme_events/admin/list.html.twig', [
            'events' => $this->events->all(),
            'page_size' => $this->settings->get('events.page_size'),
            'errors' => $errors,
        ], $errors === [] ? 200 : 422));
    }
}
