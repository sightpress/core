# 0071 — Text notifications and the outbox

- Status: Proposed
- Date: 2026-11-25

## Context

Plan §1.2 *SMS notifications* (delivery), N-4, and stage-4 gap 8: notifications that go by email should also go by
text to people who chose it. The plan says "queue + `schedule:run`", but core has no job queue; the review
notification is sent directly by `ReviewRequestMailer`.

## Decision

- **Routing.** `TextMessages::channelsFor($account, $notification)` answers with `NotificationChannel` values from
  module-interface: always `Email`, plus `Sms` while text messages are on and the person has a confirmed number,
  ticked *Send me text messages*, chose this notification and hasn't replied STOP. `notify()` puts the text in the
  outbox in the person's language. The first notification is `review_request` (`ReviewRequestMailer`).
- **Outbox (gap 8).** No new queue dependency: a notification is a `sms_message` row with status `queued`, its number
  (`to_e164`) and text, written after the checks that don't depend on time (number, country, STOP). `schedule:run`
  (`SmsOutboxJob`) sends up to 50 a run through `SmsSender::deliver()`, which runs every check again (STOP, the site's
  limit, the budget). Number and text are cleared once sent or failed. Security codes are never queued: they are sent
  at once, because people wait for them.
- **At most once.** A row is taken with a conditional update (`queued` → `sending`) that only one run can win. A row
  left `sending` for an hour (the run crashed) is marked failed (`interrupted`) and never sent again: one missed
  notification is better than two.
- **Texts.** Short, plain, in the person's language from the catalogues. They hold **no page content** (not even the
  page title) and **no link**, so people never learn to follow links in texts; they say what happened and that the
  admin panel has the rest. A test checks each one fits in two parts in every catalogue (GSM-7 or UCS-2) with a
  realistic site name.
- **Screen.** *Text messages* (`/admin/sms`, `sms.manage`, under Settings): whether the site can send and with
  which provider, the month's budget (warning from 80 %, used up at 100 % with the reserve for codes explained), the
  latest 50 messages (when, why, the masked number, parts, what happened; never the text), and *Send a test
  message* (audited). Setting up the provider stays with the operator (`site:sms:set`).
- **Retention.** The log keeps a message for 90 days (`SmsLog::KEEP_DAYS`), webhook reports for 30; the job forgets
  older ones. The Privacy Policy starter gets a *Text messages* section while text messages are on.

## Consequences

- Text notifications wait up to one `schedule:run` interval; the skeleton runs it every minute.
- Adding a notification type is a constant in `TextMessages::NOTIFICATIONS`, a label and a `notify()` call.
- The interrupted state needs no manual step; it shows on the screen as "Not delivered (interrupted)".
