# 0029 — Scheduled publishing and `schedule:run`, the one command for jobs at a set time

- Status: Proposed
- Date: 2026-11-18

## Context

P-3 publishes approved pages at a set time and takes published ones off the site at another, and later tasks need
jobs at a set time too (AN-1's roll-up, retention clean-ups). Open points: where times live and in which zone, whose
permissions a scheduled step uses, how other modules add jobs without a new contract in `module-interface`, how two
runs are kept from overlapping without new dependencies, and what happens to a step that can't be taken.

## Decision

- **Storage**: `pages.publish_at`, `pages.unpublish_at` (UTC, nullable) and `pages.scheduled_by` (user id), with an
  index on `(state, publish_at)` and `(state, unpublish_at)`. People type times in their own time zone (the `Viewer`
  time zone: their preference, else the site's) in `datetime-local` fields; `Page\Schedule\LocalTime` turns them
  into UTC. A time that doesn't exist (the clocks go forward) is refused with a sentence saying so; a time that
  happens twice (the clocks go back) is the second one, as PHP reads it.
- **Rules** (`Page\Schedule\PageSchedule`): only people with `page.publish` schedule; archived pages and pages in the
  trash can't be; a published page only gets an end time; times must be still to come, the end after the start.
  `publish_at` is acted on only while the page is **approved**: on a draft it waits, and the page is published on the
  first run after it is approved. `unpublish_at` is acted on only while the page is published.
- **Steps go through the workflow** as the person who scheduled them, with the permissions they have at that moment
  (`PermissionLookup`), so the voter and the publish checks apply exactly as if they had pressed the button. A step
  that can't be taken (checks fail, the person lost the permission or was deleted) is reported, recorded in the audit
  log (`page.schedule_failed`) and that part of the schedule cleared, so it isn't retried every minute.
- **Idempotent by state**: `Page::changeState()` forgets a `publish_at` once the page is published (or archived /
  trashed) and an `unpublish_at` once it leaves the published state, so a second run finds nothing due. A missed run
  is caught up by the next one (`<= now`), never minute by minute.
- **`schedule:run`** (in the site module) runs every enabled site: for each it dispatches
  `Site\Contract\ScheduleDue` (site, the site's container, `now`) on the root event dispatcher, like
  `SiteProvisioning`. Modules add jobs with `subscribers()->listen(ScheduleDue::class, Job::class)`, so no new
  registrar is needed in `module-interface`; the page module's job is `Page\Schedule\ScheduledPages`. A site whose jobs
  throw is reported and the others still run (exit code 1).
- **No overlap**: an exclusive, non-blocking `flock()` on `var/schedule.lock` in the project (no new dependency). A run
  that can't get it says so and stops with exit code 0. The operating system lets go of the lock when the process
  ends, even after a crash. The lock covers runs that share the project's `var/` folder, so the scheduler runs in one
  place per installation.
- **Running it**: the skeleton gets a `scheduler` service (a loop of `schedule:run` and `sleep 60`); on servers, one
  crontab line every minute.
- New events: `Page\Contract\PageScheduled` (set or cleared; audit `page.scheduled`) and
  `Page\Contract\ScheduledStepFailed`. Published or unpublished on schedule, the usual `PageTransitioned` is
  dispatched, with the comment "Published on schedule." / "Taken off the site on schedule.".

## Consequences

- A job class listening to `ScheduleDue` depends on core, not only on `module-interface`; if external modules need
  jobs, a `schedule()` sub-registrar can wrap the same event in a later `module-interface` minor.
- Steps happen within a minute of their time, not on the second.
- Two scheduler hosts that don't share `var/` could both run; that is an operator error the docs warn about (the
  workflow still refuses a second publish of a page that is already published, but the audit log could show a
  failed attempt).
