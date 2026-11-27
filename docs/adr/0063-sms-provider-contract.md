# 0063 — Text message (SMS) provider contract

- Status: Accepted
- Date: 2026-11-24

## Context

M4 adds optional SMS: notifications, phone sign-in and two-step confirmation (plan §1.2, D28–D37). Providers
(D29, gap 5) are adapters, and only some ship with core, so the provider interface must be public (N-1).

## Decision

- `Sms\SmsProvider` (implemented by modules): `id()`, `label()`, `description()` (who runs it, where data is
  processed, how long it is kept), `capabilities()`, `send(SmsMessage, SmsConnection): SmsResult` and
  `lookup(PhoneNumber, SmsConnection): PhoneLookupResult`. Optional `SmsWebhooks::readWebhook()` checks the
  signature and returns `SmsReceipt`s (delivered / failed) and `SmsOptChange`s (STOP / START). Registered with
  `sms()->addProvider()`. Adapters call out through core's shared HTTP client (ADR-0040 rules).
- `PhoneNumber` holds E.164 only. It is personal data: `masked()` (`+44•••••••123`) in dumps, not serialisable,
  and its validation error never contains the number. Parsing national formats is core's (libphonenumber).
- `SmsConnection`: key (masked, not serialisable), account id, region and sender (E.164 or a 3–11 character
  alphanumeric id), so one shape fits Twilio, Vonage, Sinch and AWS.
- `SmsMessage`: number, text, `SmsPurpose` (`security_code` or `notification`) and an optional reference. It works
  out GSM-7 or UCS-2, length and parts, refuses more than 6 parts, and refuses any link in a security code message
  (D30: codes are typed on a page).
- `SmsCapabilities`: lookup, delivery receipts, STOP replies, alphanumeric sender and the countries served.
  `PhoneLookupResult`: valid, `PhoneLineType` (premium rate is refused by core), country.
- Failures throw `SmsException` with an `SmsFailure` (invalid key, invalid number, unsupported country, opted out,
  no credit, rate limited, timeout, unsupported, invalid signature, unavailable), without the number, text or key.
- `Notification\NotificationChannel`: `email` or `sms`, for N-4's per-user routing.

## Consequences

- N-2 builds the launch adapters, budget, limits and webhook route on this; other providers can follow as modules.
- Replay protection, budgets and abuse limits are core's (N-2), not the provider's.
