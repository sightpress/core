# Signing in and out, and choosing a password (C-3.3)

## What it does

Every site has one sign-in page, `/login`, for every role: admins, editors, contributors and members. After
signing in, people go back to the page they came from. People who can't use the admin panel (every member) are
never sent there: they go to the home page instead.

- **Sign in** (`/login`): email address and password. A wrong address, a wrong password and an account that can't
  sign in all get the same message: "Enter a correct email address and password."
- **Keep me signed in** (off unless ticked): the browser stays signed in on that device for 30 days, even after it
  closes. Only tick it on a device that only you use.
- **Sign out** (`/logout`): asks you to confirm with a button, then signs you out and forgets the device.
- **Forgot your password?** (`/password/forgot`): enter your email address and we send a link to choose a new
  password. The page looks the same whether or not the address has an account, so nobody can use it to find out who
  has one. The link works for 1 hour and only once.
- **Choose your password**: the first admin of a new site gets a link to choose their first password (C-2.3). It
  works for 7 days and only once.

## Who uses it

- **Everyone with an account.**
- **Site owners**: set `MAILER_DSN` so the password emails are sent.
- **Developers**: `Actor::fromRequest($request)` tells you who is signed in on any request to a site.

## Passwords

- At least 12 characters, at most 256, and not your email address. No rules about kinds of characters: a few
  unrelated words make a strong password that is easy to remember. Spaces count.
- Stored only as an argon2id hash. A hash made with older settings is replaced the next time the person signs in.

## Security

- 5 sign-in attempts in 15 minutes per email address and client address; then the page says how many minutes to
  wait. 3 password links an hour per address.
- Signing in gives the session a new id. Changing the password signs out every other session and forgets every
  "keep me signed in" device; deactivating an account signs it out on its next request.
- Password links are signed (HMAC-SHA256 with a key derived from `APP_SECRET`) and nothing about them is stored.
  Setting a password changes what the signature covers, so every earlier link stops working.
- "Keep me signed in" cookies (`__Host-sightpress_remember` over HTTPS) hold a random selector and secret; the
  database keeps only a SHA-256 hash of the secret (`auth_remember_tokens` table). Each one works once and is
  replaced every time it is used.
- Every form has a CSRF token; the pages are never cached and never indexed.

## Settings

| Where | Setting | Default | What it does |
| --- | --- | --- | --- |
| Environment | `MAILER_DSN` | `null://null` (nothing is sent) | How email is sent, for example `smtp://user:pass@mail.example.org:587`. |
| Environment | `APP_SECRET` | — | Needed for password links (and site DSNs). |
| `config/tenant.php` | `mail.from` | `no-reply@localhost` | Sender address of every email. |
| `config/tenant.php` | `mail.from_name` | `SightPress` | Sender name of every email. |

## Accessibility

- One `<h1>` per page, a banner with the site's name, and the form in `<main>`.
- Every field has a visible label and the right `autocomplete` (`username`, `current-password`, `new-password`), so
  password managers and browsers fill them in.
- After a failed submit, the error summary at the top gets focus and links to the field; the page title starts with
  "Error:".
- "You have signed out." and "Your password has been changed." are shown in a polite live region (`role="status"`).
- Emails have an HTML part and a plain-text part with the same content.

## Code and tests

- `src/Auth/` (`AuthModule`, `SignIn`, `SessionAuthenticator`, `RememberTokens`, `PasswordReset`,
  `PasswordResetTokens`, `PasswordHasher`, `PasswordPolicy`, `ReturnTarget`, `Http/*Handler`),
  `resources/views/auth/`, `src/Http/Security/AuthenticationMiddleware.php`, `src/Http/Session/Flash.php`,
  `src/Infrastructure/Mail/`, `src/User/Contract/`, `src/Rbac/Contract/PermissionLookup.php`
- `tests/Unit/Auth/`, `tests/Integration/Auth/SignInFlowTest.php`, `tests/A11y/sign-in.spec.ts` (with the test site
  in `tests/A11y/sign-in/`)
