# Verification codes: we never send links (C-3.7)

## What it does

Every message a site sends to check that someone can read an email address (later also a phone number) carries a
**6-digit code** that the person types on a page of the site. It **never carries a link**. That way people learn one
simple rule: a genuine message from a SightPress site never asks you to click anything, so a message that does is
not from us.

Codes are used for:

| Purpose | Where it is typed | Works for |
| --- | --- | --- |
| Password reset | `/login/forgot/code` | 15 minutes |
| Invitation | `/login/invitation` | 7 days |
| First admin / new admin setup | `/login/setup` | 7 days |
| Email verification, email change, phone verification, two-step confirmation | (later milestones) | 15 minutes by email, 10 by text message |

## Rules

- A code is 6 random digits. It works **once**, and dies after **5 wrong tries**.
- There is one live code per purpose and address: asking for a new one makes the old one stop working.
- "Send a new code" works once a minute while the last code is still unused (after a code was used, a new one can be
  sent at once); the page says how long to wait, in words. An address gets at most 5 codes
  an hour, and one client at most 20; one client may try at most 10 codes in 15 minutes.
- The answer, and how long it takes, is the same whether or not the address has an account.
- Emails (HTML and plain text) name the site by its name, have the code only in the body (never the subject), and
  have **no URL, no link, no web address and no image**. They end with: "We will never send you a link. Type this
  code on the page where you asked for it. Never share it with anyone."

## Accessibility

- One text field labelled "6-digit code" (`inputmode="numeric"`, `autocomplete="one-time-code"`), never six boxes.
  Spaces and dashes are fine, so "123 456" and "123-456" both work.
- The form never sends itself when six digits are typed (WCAG 3.2.2): the person presses **Continue**.
- "Send a new code" is a real button that is never silently disabled; if it is too soon, the error summary says how
  long to wait.
- Errors appear in the error summary at the top, which gets focus, and are linked to the field.

## For developers

- Public contract `SightPress\Core\Auth\Contract\VerificationCodes` (ADR-0017): `issue()` for codes an admin or
  operator hands out, `request()` for codes a visitor asks for (rate-limited), `check()` to use one up.
  `CodeEntry::field()` gives the shared field; pages include `@auth/code-entry.html.twig`, emails
  `@auth/email/code.*.twig`.
- Table `verification_code` (Auth module): purpose, channel, account id, HMAC of the address, HMAC-SHA256 of purpose
  + address + code (key derived from `APP_SECRET`), attempts, `expires_at`, `consumed_at`. The code itself is never
  stored.
- Rate limits `verification_target`, `verification_client` and `verification_check` are in
  `Http\RateLimit\RateLimiters::POLICIES`.
- Tests: `tests/Unit/Auth/VerificationCodeStoreTest.php`, `tests/Integration/Auth/SignInFlowTest.php`,
  `tests/Integration/People/InvitationFlowTest.php`, `tests/Integration/Site/SiteCreationTest.php`,
  `tests/A11y/sign-in.spec.ts`, `tests/A11y/people.spec.ts`.
