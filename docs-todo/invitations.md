# Inviting people (C-3.4)

## Who can invite whom

- **Admins** (`user.manage`) can invite someone with any role: admin, editor, contributor or member (and any
  custom role).
- **Editors** (`user.invite` only) can invite **contributors** only. Their form shows just that role, already
  chosen. The server refuses any other role, whatever the browser sends.
- Contributors and members can't invite anyone.

## Sending an invitation

In the admin, choose **Invite someone** (`/admin/users/invite`). Give:

- the person's **email address** (an address that already has an account is refused);
- their **role** (each role is described in plain words next to its name);
- optionally their **first and last name**, which prefill their form (they can change them).

The person gets an email, as HTML and plain text, saying who invited them, to which site, with which role, and a
link that works **once, for 7 days** (the end date is given in the site's time zone). Inviting the same address
again replaces the earlier invitation, so only the newest link works.

## Accepting an invitation

The link opens `/invite/…` in the site's own design (never indexed by search engines). The person gives their
first and last name (both needed), an optional display name, their time zone (chosen from their browser's time zone
when it can tell, otherwise the site's), and a password of at least 12 characters. Their account gets the role
from the invitation and a picture of their initials, and they are sent to sign in, with a message read out by
screen readers. A used, replaced or expired link explains what happened and to ask for a new invitation.

## Email policy

Only `email_policy: any` exists in M1: every address may be invited. The check runs both when inviting and when
accepting (`People\EmailPolicy`), so limiting sign-ups to an organisation's domains (M4) plugs in there.

## For developers

- Module `people` (`src/People`), always on. Table `invitations`; only a SHA-256 of each link's token is stored.
- Contracts used: `Rbac\Contract\RoleGrants` (who may give which role), `User\Contract\AccountRegistration`
  (create an account with its avatar), `Auth\Contract\Passwords` (password rules + argon2id hashing).
- Tests: `tests/Unit/People`, `tests/Integration/People/InvitationFlowTest.php`, `tests/A11y/people.spec.ts`.
