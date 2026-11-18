# Self-registration (U-1)

People can make their own account on a site, if an admin allows it. It is off by default: until then only people
who are invited can join. ADR-0030.

## Turning it on

**Site settings → Change who can sign up** (`/admin/settings/sign-up`, admins with `settings.manage`):

- **Let anyone create an account** — off by default. When it is on, the sign-in page shows "New here? Create an
  account".
- **Role of people who sign up** — `Member` by default. Only roles that are safe to give to strangers are listed
  (for example `Contributor`); admin and editor never are, and the site refuses them whatever is sent.

Every change is recorded in the audit log.

## Signing up

1. **Create an account** (`/register`): first name, last name, an optional display name, email address, time zone
   (your browser's is already chosen) and, on a site in several languages, your language. Then a password that meets
   the site's rules (the hint says which).
2. **Confirm your email address** (`/register/code`): we email you a **6-digit code**. The email never has a link: type
   the code on the page. It works for 15 minutes and only once; "Send a new code" sends another (once a minute).
3. **Sign in** with your email address and password. You have the site's sign-up role (`Member` unless the admin
   chose another) and a generated picture.

Until the code is typed the account can't sign in. Signing in with the right password takes you back to the code
page. An account that is never confirmed is removed a week after it was made (by `schedule:run`).

## Privacy and spam

- If the address already has an account, the page says exactly the same as for a new one; the owner gets an email
  saying someone tried to sign up with their address (no link, nothing changed).
- A hidden field catches bots; each place can send at most 10 sign-up forms an hour, and the code limits apply.
- A role sent with the form is ignored.

## For developers

- Status `unverified` (`UserStatus::Unverified`), `User\Contract\UnverifiedAccounts`, `Auth\Registration\SelfRegistration`.
- Setting `people.registration` (`1` = open), `rbac.registration_role` through `Rbac\Contract\SignUpRoles`.
- Event `Auth\Contract\AccountRegistered` → audit `user.registered` (with `refused_role` when an unsafe stored role
  fell back to `member`).
- The email policy is `Auth\Contract\EmailPolicy` (any address for now).
