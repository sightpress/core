# 0062 — Route permissions and critical routes

- Status: Accepted
- Date: 2026-11-24

## Context

Core's admin routes add `AdminAccessMiddleware` and check permissions in the handler. An external module can't use
that middleware (it is core's), and N-6 needs a way to mark a module's action as critical for two-step
confirmation (plan §1.2, D33).

## Decision

- Add two optional arguments to `Routing\Route`, at the end, so existing modules are unaffected:
  - `permission` (`?string`): core checks it before the handler. Signed out → `303` to `/login?return=…`;
    without the permission → core's "no access" page (`403`). Paths under `/admin` also need `admin.access`, as
    core's own admin screens do.
  - `critical` (`bool`, default `false`): sites with two-step confirmation ask for a code before the route runs
    (N-6). Only allowed on routes that answer `POST`, `PUT`, `PATCH` or `DELETE`; `GET` is never held up.
- Core honours `permission` from the PR that requires `^0.5`, and `critical` from N-6 (until then the flag is kept
  and listed, and does nothing).

## Consequences

- Module authors stop writing their own sign-in middleware for the common case.
- Core's own routes can move to `permission` gradually; nothing forces it.
