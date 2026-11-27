# 0069 — Text messages (SMS module)

- Status: Accepted
- Date: 2026-11-25

## Context

Plan §1.2 *SMS notifications*, D28–D32 and stage-4 gaps 5, 6 and 10: sites need text messages for phone
verification codes (N-3), notifications (N-4), phone sign-in (N-5) and two-step confirmation (N-6). Sending them
costs money per message and per number lookup, a public form that sends codes can be abused to run up charges
(SMS pumping), and the numbers are personal data sent to a third party. module-interface 0.5 already has the
`SmsProvider` contract, `SmsWebhooks` and `sms()->addProvider()` (MI-0.5).

## Decision

- **Module.** `SmsModule` (`sms`) is optional and off by default. Turning it on needs a provider that is set up:
  `SiteModules::turnOn()` asks a module's `Tenant\ModuleTurnOnCheck` (site service `module.turn_on_check.<id>`) and
  refuses with its message, from the Features screen and the console alike.
- **Providers (gap 5).** Core ships two: **Twilio** (Messages API, Lookup v2 line type, `X-Twilio-Signature`) and
  **Vonage** (SMS API, Number Insight Standard, signed webhooks with `sig` + `timestamp`). Both have lookup, delivery
  receipts, STOP replies and alphanumeric senders, cover the UK and the EU, and have test accounts. Sinch and AWS End
  User Messaging can follow as modules, since `SmsProvider` is public. Vonage needs two secrets; its key is
  `api-secret|signature-secret`.
- **Setup.** The tenant may give a default in `sms` (`provider`, `account`, `sender`, `region`, key from
  `SMS_API_KEY`). The operator sets a site's own provider with `site:sms:set <site> --provider= [--account=]
  [--sender=] [--region=] [--budget=] --key-stdin`; the key is encrypted with `APP_SECRET`, never shown again
  (`••••1234`) and never logged. `site:sms:remove|enable|disable|test` complete the set. `sms.site_providers`
  (default false) is read by the Text messages screen (N-4).
- **One place sends.** `SmsSender::send()` and `check()` hold every guard (gap 6): only numbers libphonenumber says can
  get texts (mobile, or "fixed line or mobile"; never premium-rate, shared-cost, toll-free or landline), only in the
  allowed countries (`sms.countries`, by default the country of the site's main language), the provider's lookup for
  line type, no notifications to numbers that replied STOP, per-hour limits per number, client address and account
  (security codes) and per site (everything), a daily cap on paid lookups, and the monthly budget in message parts.
  **A reserve** of the budget (`sms.reserved_for_codes`, default 50) is only for security codes, so a used-up budget
  never locks people out (gap 10); STOP stops notifications, not codes someone asked for.
- **Log.** `sms_message` keeps one row per message: purpose, the number masked and as an HMAC (APP_SECRET + site),
  user, provider and its id, parts, status and failure. The text is never stored for codes, and for an outbox
  notification only until it is sent. `sms_opt_out` holds HMACs of numbers that replied STOP. The budget counts parts
  the provider accepted.
- **Webhook.** `POST /_sms/webhook` (no session, no CSRF token, open on members-only sites) passes the request to the
  provider's `readWebhook()`, which checks the signature; 403 when it fails, 404 when the provider has none. A request
  already handled (same provider, query and body) changes nothing, and Vonage requests older than 5 minutes are
  refused. Receipts update the log; STOP and START update `sms_opt_out` and are audited with the number masked.
- **Errors.** `SmsError` turns each `SmsFailure` into a plain-language message; `Sms\Contract\SmsRefused` carries it to
  other modules. Messages never contain the number, the text or the key.
- **Audit.** Settings changes (keys only), on/off, test messages and STOP/START replies, all with masked numbers and
  actor `null` for the console.

## Consequences

- N-3 to N-6 build on `SmsSender` without their own guards; other modules reach it only through Contract types.
- Two adapters to maintain; a third provider is a module, not a core change.
- Twilio signs the URL it called: behind a proxy that rewrites the scheme or host, the webhook URL configured at
  Twilio must match what the app sees, or every report is refused (403). The docs say so.
- libphonenumber (`giggsey/libphonenumber-for-php`) is a new dependency; it works offline, so the free checks run
  before any paid lookup.
