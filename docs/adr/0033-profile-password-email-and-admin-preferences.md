# 0033 — Profile: password and email address changed from My account, and admin preferences as classes

- Status: Accepted
- Date: 2026-11-18

## Context

U-3 finishes *My account* (C-3.6): people change their password and their email address there, and people who use
the admin panel can ask it for less motion and larger text. The language choice (C-3.6, I-3) and resetting people
whose language a site drops (I-1) already exist. Every verification uses a 6-digit code typed on a page, never a link
(D30), and every change must be audited without its values.

## Decision

- **Pages in the Auth module**, linked from a "Signing in" section of *My account*: `/account/password`,
  `/account/email` and `/account/email/code`, in the sign-in pages' layout. Auth owns passwords, codes and sessions,
  so the User module keeps no password code; both modules only meet through `User\Contract`.
- **The current password** is needed for both changes. `SignIn::confirmPassword()` checks it against the same
  `sign_in` rate limit as signing in (key: address and client), so the pages can't be used to guess faster.
- **A new password** must meet the site's policy and differ from the current one. It is hashed, every "keep me signed
  in" token of the account is deleted (and this browser's cookie removed), and this session starts again with a new id
  and the new password stamp — every other session is signed out by the stamp check that already exists.
- **A new email address** gets an `email_change` code (`VerificationCodes::request()`, one a minute, the usual
  limits). The account keeps its address until the code is typed back on `/account/email/code`, by the same account,
  while the address is still free. The old address then gets a notice with the new one masked (`m•••@new.example`),
  no link, no web address and no image. The site's `EmailPolicy` applies, as for signing up. An address that already
  has an account gets the same answer but no code, so nobody learns which addresses have accounts.
- **`User\Contract\Account::changeEmail()`** is added to the contract (the one change to a contract here), so Auth
  can move the account without reaching into the User entity.
- **Admin preferences** (`admin_reduced_motion`, `admin_larger_text` on `users`, both off) are offered only to
  people with `admin.access` (`POST /account/preferences`, 403 otherwise). They travel with the other display
  preferences (`ViewerPreferences::$reducedMotion`, `$largerText`) and the admin shell puts them on `<html>` as the
  classes `admin-reduced-motion` and `admin-larger-text`; `admin.css` does the rest (root font size 125 %, no
  animations, transitions or smooth scrolling). No script; the public site is never affected.
- **Audit**: `AccountChanged` with the fields `password`, `email` or `admin_preferences` — never the values.

## Consequences

- A password change sends no email yet; a notice like the email one can be added with N-5 notifications.
- Step-up 2FA for these changes (plan D33–D37) can later sit in front of the same pages.
- Sites with a single language still show the language choice, because it also sets the admin panel's language.
