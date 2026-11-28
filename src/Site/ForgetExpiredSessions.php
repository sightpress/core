<?php

declare(strict_types=1);

namespace SightPress\Core\Site;

use SightPress\Core\Http\Session\ExpiringSessionStore;
use SightPress\Core\Http\Session\SessionStore;
use SightPress\Core\Site\Contract\ScheduleDue;

/**
 * The site module's job in `schedule:run`: removes the site's sessions that ended (no request for the idle timeout),
 * from stores that don't end them by themselves (the database and files; Redis does).
 */
final readonly class ForgetExpiredSessions
{
    public function __invoke(ScheduleDue $event): void
    {
        if (!$event->services->has(SessionStore::class)) {
            return;
        }
        $store = $event->services->get(SessionStore::class);
        if (!$store instanceof ExpiringSessionStore) {
            return;
        }

        $removed = $store->forgetExpired($event->now);
        if ($removed > 0) {
            $event->report(sprintf('Removed %d %s that ended.', $removed, $removed === 1 ? 'session' : 'sessions'));
        }
    }
}
