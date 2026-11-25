# Text notifications

People who added a mobile number and ticked **Send me text messages** on *My account* get the notifications they
chose by text as well as by email. Email always goes.

## Which notifications

| Notification | When |
|---|---|
| *When a page is sent to you for review* | A contributor sends a page for review and you can publish it. |

Texts are short and in the person's language. They never contain the page's title or any other content, and never a
link: they say what happened and that the admin panel has the rest.

## How they are sent

Notifications wait in an outbox and are sent by `bin/sightpress schedule:run` (the skeleton runs it every minute), up
to 50 a run. Each one is checked again just before it goes: a STOP reply, the site's hourly limit and the monthly
budget can stop it. A run that crashes never sends a message twice: a message it left half-sent is marked "Not
delivered (interrupted)" after an hour.

Security codes are not queued: they go at once.

## The Text messages screen

**Settings → Text messages** (`/admin/sms`, for people with *Manage text messages*, admins by default) shows:

- whether the site can send, and with which provider;
- how much of this month's budget is used (a warning from 80 %; at 100 % only security codes still go, from a small
  reserve);
- the latest 50 messages: when, security code or notification, the number partly hidden, parts, and what happened.
  The text is never kept;
- **Send a test message**, which counts towards the budget and is recorded in the audit log.

The log keeps messages for 90 days.

## Privacy Policy

New sites made while text messages are on get a *Text messages* section in the starter Privacy Policy. For an
existing site, add it yourself: what numbers are used for, that the provider receives the number and the text, how
long they are kept, and how to stop texts (My account, or reply STOP).
