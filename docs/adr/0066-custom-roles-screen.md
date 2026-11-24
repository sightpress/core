# 0066 — Custom roles on the roles screen and the command line

- Status: Proposed
- Date: 2026-11-24

## Context

`RoleManager` already applies every RBAC rule (D24, ADR-0014, ADR-0016), but nothing let a site admin use it: roles
could only change through code. X-4 adds the *Roles* screen and `site:role:*` commands. Three questions came up
that the rules didn't answer: what a site shows for permissions of features that are off, whether the two system
roles can be edited at all, and how an admin is kept from locking themselves out.

## Decision

- **One service for the screen and the CLI.** `Rbac\Contract\Roles` (implemented by `RoleAdministration` on top of
  `RoleManager`) lists, creates, changes and deletes roles, and returns each refusal as a plain sentence keyed by
  what it is about (`label`, `role` or a permission name), so the screen puts it on the right field and the CLI
  prints it. The People module renders the screens; it only sees the contract and summary objects.
- **Admin and Member are fixed.** Admin always has every permission (the seeder gives it each new one) and Member
  never has one, so neither can be changed; their pages only say what they can do. A forged change is refused.
  Editor and Contributor are seeded but ordinary: they can be changed and, when nobody has them, deleted.
- **Features that are off are hidden and kept.** Only permissions of modules that are on for the site are listed
  and can be given; a role keeps what it holds of the others, so turning a feature back on restores it. Permissions
  are grouped by area (the part of the name before the first dot), known areas first in menu order.
- **No self lock-out.** A change that would leave the person making it without `role.manage` is refused. The
  command line has no person, so it can always repair a site. Nobody changes their own role already (C-3.5), and
  Admin can't lose a permission, so the last admin stays an admin.
- **Audited.** Each change dispatches `Rbac\Contract\RoleEdited` (created, renamed, permissions changed with what
  was added and removed, deleted), recorded with the type `role`; the CLI is recorded as the command line. The event
  stays in core for now: no external module needs it yet (a candidate for the stable list).
- Role names (`shop_staff`) are made from the label on the screen and typed on the command line; the label can be
  changed later, the name can't.

## Consequences

- Site admins can make roles without a developer; the sign-up guardrail and the rules of D24 still hold, checked on
  the server whatever is posted.
- Checkbox groups gained per-option descriptions (`Field::$choiceHints`), linked with `aria-describedby`.
- A permission from a removed package stays on roles until an admin deletes the role; it isn't shown anywhere.
