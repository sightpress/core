# 0061 — Scheduled jobs for modules

- Status: Accepted
- Date: 2026-11-24

## Context

`schedule:run` (ADR-0029) dispatches `ScheduleDue` once per site; core's jobs listen to it. `ScheduleDue` carries
core's `Site` and the site container, so it can't be in the contract (ADR-0057).

## Decision

- Add `Schedule\ScheduledJob::run(ScheduleRun $run)` (implemented by modules) and a `schedule()` registrar.
- `ScheduleRun` is a final class: `now` (UTC, the same for every job of the run) and `report(line)` / `lines()`,
  which the command prints. Modules can build one in their tests.
- Core runs the jobs of modules that are on for the site, after its own `ScheduleDue` listeners, each built from
  the site container. A job that throws is reported and logged; the other jobs and sites still run.
- Jobs decide themselves what is due, must be quick, and must be safe to run again after a crash.

## Consequences

- Modules get background work without a queue (gap 8 keeps the "no queue" decision).
- Core's own jobs can move to the contract later; `ScheduleDue` stays internal until then.
