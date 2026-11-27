# 0072 — Signing in and resetting the password with a mobile number

- Status: Accepted
- Date: 2026-11-25

## Context

Plan §1.2 *Phone sign-in*, N-5, stage-4 gap 9 and the carried-over "no email after a password change" (stage 2).
Once people have a confirmed number (N-3), they should be able to sign in with it and reset a forgotten password with
a code by text. A reset by text weakens an account: whoever controls the number (a SIM swap, a recycled number) can
take it over, and with two-step confirmation (N-6) the same phone would then pass both steps.

## Decision

- **One identifier field.** While text messages are on, the sign-in and *Forgot your password?* field is "Email address
  or mobile number" (`type=text`, `autocomplete=username`, with a hint about `+` and the country code). With text
  messages off it is "Email address" again, and numbers never match; email always works.
- **Reading it.** `Auth\SignInIdentifier`: with an `@` it is an email address; otherwise libphonenumber reads it as a
  number of the site's country (or with its own `+` code) and it matches only a **confirmed** number
  (`users.phone_e164`). Changing the number turns the old one off at once.
- **Same answer.** A wrong number, an unconfirmed number, another site's number and a wrong email address get the same
  error ("Enter a correct email address or mobile number, and password."), status and timing.
- **One throttle per account.** `sign_in` attempts are counted per account when the identifier names one, otherwise
  per identifier (each with the client address), so alternating email and number doesn't start the count again.
  Confirming the password on *My account* uses the same per-account count.
- **Reset by text.** A number gets the reset code by text (10 minutes, "Never share it", no link), counted in the
  site's SMS limits and budget; an address gets it by email as before. The answer is the same whether or not anything
  was sent. **Gap 9:** accounts with a permission that manages the site (`user.manage`, `role.manage`,
  `settings.manage`, `module.manage`, `security.manage`) never reset by text: no code is sent to them and a code is
  refused if they got that power meanwhile. N-6 adds "and not while two-step confirmation is on". The pages say that
  people who manage the site reset by email only.
- **Email after every password change** (`Auth\PasswordChangedNotice`): after a change on *My account* and after a
  reset by email or by text (which says so), with what to do if it wasn't them. No link. Choosing the first password of
  a new account sends none.

## Consequences

- People without a mailbox at hand can still get back in with their phone; people who manage the site keep email as
  their way back, and the phone stays a second step for them.
- `SignIn`, `PasswordReset`, the login and forgot-password handlers depend on `SignInIdentifier` (and `TextMessages`).
