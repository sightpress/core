# Users, roles and permissions (C-3.1)

## What it does

Each site keeps its own people (users) and roles in its own database. A role is a named set of permissions, for
example "Editor". Every site starts with four roles:

| Role | What it can do |
| --- | --- |
| Admin | Everything. Can't be renamed or deleted. |
| Editor | Write, review, publish, archive and delete anyone's pages; publish media; manage menus; invite contributors. |
| Contributor | Write their own pages, submit them for review, upload media. |
| Member | Read published pages and look after their own account. Holds no permissions and can never be given any. Can't be renamed or deleted. |

## Who uses it

- **Site admins** change roles (permission `role.manage`, see `custom-roles.md`) and the sign-up role.
- **Developers** add permissions from their module (`$registrar->permissions()->add(...)`); they are copied into
  every site and given to their default roles (and to Admin).

## Permissions

`admin.access`, `page.create`, `page.edit.own`, `page.edit.any`, `page.submit`, `page.publish`, `page.archive`,
`page.delete`, `media.upload`, `media.publish`, `menu.manage`, `user.invite`, `user.manage`, `role.manage`,
`settings.manage`, `theme.apply`, `audit.view`.

## Rules

- A role that has any permission also has `admin.access` (it can open the admin panel).
- The **sign-up role** (site setting `registration_role`, default `member`) is the role people get when they
  register themselves. It must be *sign-up-safe*: every permission it holds is marked safe for strangers. Only
  `admin.access`, `page.create`, `page.edit.own`, `page.submit` and `media.upload` are. So Member and Contributor
  can be the sign-up role; Editor and Admin can't.
- You can't give the sign-up role an unsafe permission ("Choose another sign-up role first"), rename it or delete it.
- A role that people still have can't be deleted.

## User details

Email (unique on the site, stored in lower case), first and last name (required, 1–100 characters, any script),
optional display name (shown instead of the first name), time zone (IANA, for example `Europe/London`), optional
locale, avatar, and status (`pending` until a password is set, `active`, `deactivated`).

## Code and tests

- `src/User/`, `src/Rbac/`, `src/Access/PermissionCatalogue.php`, `src/Site/Contract/SiteSettings.php`,
  `src/Site/DbalSiteSettings.php`; ADR-0016
- `tests/Unit/User/`, `tests/Unit/Rbac/`, `tests/Integration/Rbac/SiteRbacTest.php`
