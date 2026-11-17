# Signing in and out, and choosing a password (C-3.3, C-3.7)

## What it does

Every site has one sign-in page, `/login`, for every role: admins, editors, contributors and members. After
signing in, people go back to the page they came from. People who can't use the admin panel (every member) are
never sent there: they go to the home page instead.

- **Sign in** (`/login`): email address and password. A wrong address, a wrong password and an account that can't
  sign in all get the same message: "Enter a correct email address and password."
- **Keep me signed in** (off unless ticked): the browser stays signed in on that device for 30 days, even after it
  closes. Only tick it on a device that only you use.
- **Sign out** (`/logout`): asks you to confirm with a button, then signs you out and forgets the device.
- **Forgot your password?** (`/login/forgot`): enter your email address and we email a 6-digit code. Type it on the
  next page (`/login/forgot/code`), then choose a new password (`/login/password`). The pages look the same, and take
  the same time, whether or not the address has an account, so nobody can use them to find out who has one. The
  code works for 15 minutes and only once. **We never send a link.**
- **Set up your account** (`/login/setup`): the first admin of a new site (C-2.3), or an admin made with
  `site:user:create-admin`, types their email address and the setup code the operator gave them, then chooses their
  first password. The code works for 7 days and only once.
- **Accept an invitation** (`/login/invitation`): see `invitations.md`.

Codes are explained in `verification-codes.md`.

## Who uses it

- **Everyone with an account.**
- **Site owners**: set `MAILER_DSN` so the emails with codes are sent.
- **Developers**: `Actor::fromRequest($request)` tells you who is signed in on any request to a site.

## Passwords

- Every site has a **password policy** (ADR-0022), set by its admins in *Site settings → Passwords*. By default a
  password needs at least 12 characters, a capital letter, a lower-case letter, a number and a symbol (such as
  `! ? # %`). The shortest allowed can be set from 8 to 256, and each kind of character can be switched off.
- Always: at most 256 characters and not your email address. Spaces are allowed (they don't count as a symbol).
  Letters, numbers and symbols count in any script.
- The hint under every password field says what the site's policy needs; an error names everything that is missing.
- A new policy applies the next time someone chooses a password; current passwords keep working.
- Stored only as an argon2id hash. A hash made with older settings is replaced the next time the person signs in.

## Security

- 5 sign-in attempts in 15 minutes per email address and client address; then the page says how many minutes to
  wait. Codes have their own limits (see `verification-codes.md`).
- Signing in gives the session a new id. Changing the password signs out every other session and forgets every
  "keep me signed in" device; deactivating an account signs it out on its next request.
- Only an HMAC of each code is stored (key derived from `APP_SECRET`); a right code is used up, and a code dies
  after 5 wrong tries. A checked code lets that browser session choose a password for a short while only.
- "Keep me signed in" cookies (`__Host-sightpress_remember` over HTTPS) hold a random selector and secret; the
  database keeps only a SHA-256 hash of the secret (`auth_remember_tokens` table). Each one works once and is
  replaced every time it is used.
- Every form has a CSRF token; the pages are never cached and never indexed.

## Settings

| Where | Setting | Default | What it does |
| --- | --- | --- | --- |
| Environment | `MAILER_DSN` | `null://null` (nothing is sent) | How email is sent, for example `smtp://user:pass@mail.example.org:587`. |
| Environment | `APP_SECRET` | — | Needed for verification codes (and site DSNs). |
| `config/tenant.php` | `mail.from` | `no-reply@localhost` | Sender address of every email. |
| `config/tenant.php` | `mail.from_name` | `SightPress` | Sender name of every email. |

## Accessibility

- One `<h1>` per page, a banner with the site's name, and the form in `<main>`.
- Every field has a visible label and the right `autocomplete` (`username`, `current-password`, `new-password`,
  `one-time-code`), so password managers and browsers fill them in.
- The code is one text field labelled "6-digit code" with a numeric keyboard, never six boxes. Spaces and dashes
  are fine. The form never sends itself: you press **Continue**.
- After a failed submit, the error summary at the top gets focus and links to the field; the page title starts with
  "Error:".
- "You have signed out." and "Your password has been changed." are shown in a polite live region (`role="status"`).
- Emails have an HTML part and a plain-text part with the same content, and no link, web address or image.

## Code and tests

- `src/Auth/` (`AuthModule`, `SignIn`, `SessionAuthenticator`, `RememberTokens`, `PasswordReset`,
  `VerificationCodeStore`, `CodeSession`, `EvenTiming`, `PasswordHasher`, `PasswordPolicy`, `ReturnTarget`, `Http/*Handler`),
  `resources/views/auth/`, `src/Http/Security/AuthenticationMiddleware.php`, `src/Http/Session/Flash.php`,
  `src/Infrastructure/Mail/`, `src/User/Contract/`, `src/Rbac/Contract/PermissionLookup.php`
- `tests/Unit/Auth/`, `tests/Integration/Auth/SignInFlowTest.php`, `tests/A11y/sign-in.spec.ts` (with the test site
  in `tests/A11y/sign-in/`)
