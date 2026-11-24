# Custom roles (X-4)

## What it does

Site admins make their own roles — named sets of permissions such as "Shop staff" — and change or delete them, on
the *Roles* screen or the command line. Everyone has one role; it is given on the person's page in *People*.

## Who uses it

- **Site admins** with `role.manage` (Admin has it): *Roles* in the admin menu.
- **Operators**: the `site:role:*` commands.

## Screens

- `/admin/roles`: every role, how many permissions and people it has, and whether it is built in or the role of
  people who sign up.
- `/admin/roles/new` and `/admin/roles/{name}`: the role's name, then one group of checkboxes per area (Pages,
  Media, Menus, People, Roles, Site settings, Features, Appearance, Analytics, AI assistant, Audit log), each
  permission with a sentence saying what it allows.
- `/admin/roles/{name}/delete`: asks first, or says why it can't be deleted.

## Rules

- Admin (everything) and Member (nothing) can't be changed, renamed or deleted.
- A role with any permission can also use the admin panel (`admin.access` is added for it).
- Permissions of features that are off aren't listed; the role keeps them.
- The role of people who sign up only gets permissions that are safe for strangers.
- You can't take "Manage roles" away from your own role.
- A role that people have, or the sign-up role, can't be deleted.
- Every change is in the audit log (type *Roles*).

## Commands

```
site:role:list <slug>
site:role:create <slug> <name> [--label="Shop staff"] [--permissions=media.upload,menu.manage]
site:role:grant <slug> <role> <permission>
site:role:revoke <slug> <role> <permission>
site:role:delete <slug> <role>
```

## Accessibility

Checkbox groups are `<fieldset>`s with a `<legend>`; each permission's description is linked with
`aria-describedby`. Refusals go to the error summary (focused) and to the field they are about, with the boxes
still ticked. Keyboard test: `tests/A11y/roles.spec.ts`.

## Code and tests

- `src/Rbac/Contract/Roles.php`, `src/Rbac/RoleAdministration.php`, `src/People/Http/Role*Handler.php`,
  `src/People/Command/`, `resources/views/people/role*.html.twig`; ADR-0066
- `tests/Unit/Rbac/RoleAdministrationTest.php`, `tests/Unit/People/Http/RoleScreensTest.php`,
  `tests/Unit/People/Command/RoleCommandsTest.php`, `tests/Integration/People/RoleAdminFlowTest.php`
