# People in the admin (C-3.5)

Who: admins (permission `user.manage`). Editors can invite contributors (see `invitations.md`) but don't see these
screens.

## The list of people

Choose **People** in the admin navigation (`/admin/users`). Everyone with an account is listed in a table:

- **Name** (the display name, or first name, with their picture; it links to the person), **Full name**,
  **Email address**, **Role** and **Status** (Active, Hasn’t set a password yet, or Deactivated).
- Each column header is a link: choose it to sort by that column, and again to sort the other way. The table's
  caption says how it is sorted (for example "sorted by email address, Z to A"), and screen readers announce the
  sorted column.
- **Invite someone** is at the top. Below the table, **Invitations not yet accepted** lists open invitations with
  their role, who sent them and until when the link works.

On a narrow screen the table scrolls sideways inside its own area, which can be reached and scrolled by keyboard.

## One person

Choose a name to open the person's page. It shows their full name, email address, role and status, and has:

- **Change role**: choose a role (each is described in plain words) and **Change role**. The new role applies from
  their next click or page.
- **Deactivate account**: they can no longer sign in, and are signed out straight away. Their pages stay. The same
  place then offers **Reactivate**, after which they sign in with their old password.

Each result is announced, for example "Cleo’s role is now Editor.".

Nobody can change their own role or deactivate themselves. Their own page says so and links to **My account**, so a
site always keeps at least one active person who can manage people.

## For developers

- Module `people`: `UsersHandler`, `UserHandler`, `UserStatusHandler` (routes `people.users`, `people.user`,
  `people.user.status`; admin menu item `people.users`).
- Contracts: `User\Contract\AccountRoster` (+ `AccountSummary`) and `Rbac\Contract\RoleGrants::rolesOf()` /
  `change()`.
- Events for the audit log: `User\Contract\AccountChanged` with field `status`, and `Rbac\Contract\RoleChanged`
  (user, who changed it, from, to).
- Tests: `tests/Unit/People/Http/UserAdminHandlersTest.php`, `tests/Integration/People/UserAdminFlowTest.php`,
  `tests/A11y/people.spec.ts`.
