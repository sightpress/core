# 0014 — Permissions say whether sign-up roles may hold them

- Status: Accepted
- Date: 2026-11-14

## Context

People can join a site on their own: self-registration, and domain registration for members-only sites. They get
the site's `registration_role` (default `member`). If a site admin, a CLI call, a config file or a direct database
edit makes that role a powerful one, anyone who signs up gets admin rights. Core needs to know which permissions
are safe for a role that strangers can get, and modules add their own permissions, so the answer has to come from
the permission itself.

`Permission` in `sightpress/module-interface` `0.1.0` has a name, label, description and default roles only. This
is a change to a public contract (ADR-0009), so it needs an ADR.

## Decision

- Add `bool $allowedOnSignUp = false` to `SightPress\Module\Access\Permission`, as the last constructor parameter
  and a public readonly property. Release it as `module-interface` `0.2.0` (additive, so a minor version); `core`
  requires `^0.2`.
- Default to `false`, so a module's new permission is safe until its author marks it otherwise.
- Call a role **sign-up-safe** when every permission it holds is `allowedOnSignUp`. In core's catalogue only
  `admin.access`, `page.create`, `page.edit.own`, `page.submit` and `media.upload` are `true`.
- Enforce it in core: the `registration_role` must be sign-up-safe (admin picker, server, CLI and config
  validation; `admin` is refused by name); granting a non-sign-up permission to the current `registration_role`
  is refused; the role is checked again at registration and falls back to `member` with an audit entry and a log
  warning; sign-up forms ignore any role in the request; only an admin can promote a user, and that is audited.
  `member` holds no permissions, so it is always safe. Invites are not affected.

## Consequences

- Module authors must decide, per permission, whether people who sign up may hold it. The docblock tells them to
  mark only low-risk actions on the person's own content.
- Existing `Permission` calls keep working: the new parameter is optional and placed last.
- If a module wrongly marks a powerful permission as `allowedOnSignUp`, the guardrail can't catch it. Review module
  permissions when a module is added to the catalogue of recommended modules.
- Look at it again if sign-up ever needs more than one level of trust (for example a per-path role).
