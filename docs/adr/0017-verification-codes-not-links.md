# 0017 — Verification messages carry a six-digit code, never a link

- Status: Proposed
- Date: 2026-11-17

## Context

Password reset (C-3.3), invitations (C-3.4) and the first admin of a new site (C-2.3) were built with signed
links. A link in an email or text message is what phishing imitates: people who learn to click "your" links also
click fake ones. SightPress wants people to learn one simple rule instead: a genuine message from a SightPress site
never asks them to click anything. Phone verification and two-step confirmation (M4) need the same kind of code
by text message.

Other modules need these codes too (People for invitations now, an SMS module and self-registration later), so the
service has to be a public contract of the Auth module.

## Decision

- Add the contract `SightPress\Core\Auth\Contract\VerificationCodes` (public service), with the value types
  `VerificationPurpose` (`email_verify`, `email_change`, `password_reset`, `invitation`, `account_setup`,
  `phone_verify`, `step_up`), `VerificationChannel` (`email`, `sms`), `IssuedCode`, `CodeCheck`, `CodeStatus`,
  `TooManyCodes`, `VerificationTarget` (normalise + mask) and `CodeEntry` (the shared code field, wording and the
  "we will never send you a link" line).
- Make codes six digits from `random_int`, store only HMAC-SHA256 hashes of the address and of purpose + address +
  code (key derived from `APP_SECRET`) in the site's `verification_code` table, keep one live code per purpose and
  address, and kill a code after 5 wrong tries. Codes work 15 minutes by email, 10 by text, 7 days for
  invitations and setup codes.
- Limit codes a visitor asks for: one a minute per purpose and address, `verification_target` (5 an hour per
  address), `verification_client` (20 an hour per client) and `verification_check` (10 tries per 15 minutes per
  client). Answer the same way, in the same time, whether or not the address has an account.
- Add the contract `SightPress\Core\User\Contract\PendingAccounts` so the Auth module's `site:user:create-admin`
  command can create an admin who waits for a setup code, without reaching into the User module's internals.
- Remove the link-based routes (`/password/forgot`, `/password/reset/{token}`, `/invite/{token}`) and
  `PasswordResetTokens`; the flows live under `/login/…` and every verification email has no URL, no `<a>`, no web
  address and no image, and never has the code in its subject.

## Consequences

- People type a code instead of clicking, which is one more step but works the same on every device and with
  every screen reader, and builds the right habit.
- Invitations still open when the migration drops `invitations.token_hash` can't be accepted: invite those
  people again (nothing is released yet, so this only touches test sites).
- The M4 SMS work (phone verification, phone sign-in, two-step confirmation) plugs into the same service with the
  `sms` channel and the `phone_verify` / `step_up` purposes.
