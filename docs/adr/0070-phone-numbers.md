# 0070 — Phone numbers on accounts

- Status: Proposed
- Date: 2026-11-25

## Context

Plan §1.2 *SMS notifications*, D30, D31 and stage-4 gap 7. Text notifications (N-4), phone sign-in (N-5) and
two-step confirmation (N-6) need a mobile number that is proved to be the person's. D31 as written makes a number
required on every registration path while text messages are on; gap 7 points out that this is hard to defend under
UK/EU GDPR data minimisation, shuts out people without a mobile phone, and raises SMS cost and fraud exposure.

## Decision

- **Optional (gap 7).** A number is needed only by people who want text messages or, once two-step confirmation is on
  (N-6), do an important change. Everyone else may skip it.
- **One step, every way in.** Instead of a phone field on each registration form (self-registration, domain
  registration, invitations, the first admin), everyone who signs in without a number while text messages are on is
  sent to *Add your mobile number* (`/account/phone`) once per sign-in, with *Skip for now*. That covers every path,
  and existing users at their next sign-in, with one form. `sms.require_for_existing: true` removes *Skip for now*.
  The operator can give an admin's number with `site:user:create-admin --phone=` (they vouch for it; no code).
- **Storage.** `users.phone_e164` (only once its code was typed), `phone_verified_at`, `phone_lookup` (the provider's
  line type and country), `sms_consent_at`, `sms_opt_out_at`, `sms_notifications`. A **unique index on
  `phone_e164`**: NULL is allowed many times on SQLite, MySQL, MariaDB and PostgreSQL. A pending number lives in the
  session until its code is typed.
- **Flow.** Country select (the allowed countries, the site's first) and a `type=tel autocomplete=tel-national`
  field, read with libphonenumber; then `SmsSender::check()` (the free checks and the provider's lookup; a landline,
  a number abroad or a lookup timeout is a form error, never a silent accept); then a 6-digit code by text
  (`VerificationPurpose::PhoneVerify`, 10 minutes, no link), typed on `/account/phone/code`. Only then is the number
  the account's, and the old one stops working at once.
- **No enumeration.** A number another account of the site has gets the same answer and page, and no text, like an
  email address that is taken; the code can't be confirmed. The same number may be used on another site.
- **Opt-in.** Having a number subscribes no one: *Send me text messages* is unticked until ticked, with a choice of
  notifications (`TextMessages::NOTIFICATIONS`). Unticking, removing the number or replying STOP (the SMS module's
  `TextRepliesToAccounts`) sets `sms_opt_out_at`; START resumes only for someone who had opted in.
- **Contracts.** `User\Contract\AccountPhones` (+ `AccountPhone`) for other modules; `Infrastructure\Sms\TextMessages`
  is how modules other than SMS reach it (on, countries, parse, check, send a code). `TextMessageOptChanged` now also
  carries the number, for finding its account; it is never written anywhere.
- **Audit.** `AccountChanged` with `phone` or `text_messages` (field names only, never the number).

## Consequences

- No registration form gets longer; people without a mobile phone are never blocked.
- One more step after signing in while text messages are on; it is skippable and asked once per sign-in.
- The number is shown in full only to its owner (My account); masked everywhere else.
