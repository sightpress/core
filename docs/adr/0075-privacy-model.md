# 0075 — Privacy model: roles, inventory, retention, erasure by tombstone, no third parties by default

- Status: Proposed
- Date: 2026-11-27

## Context

`0.6.0` adds the privacy foundation (plan §1.2 *Privacy & data protection*, §8.0 GD-0 … GD-8, D43–D51): an
inventory of the personal data a site holds, retention, *Download your data*, *Delete your account*, a generated
Privacy Policy, cookie list and record of processing, and no third parties and no cookies for anonymous visitors.
The plan names this ADR "ADR-0037", but that number is taken (analytics), so it gets the next free number.

Before any code is written, every data set core already stores has to be listed with its category, purpose,
suggested lawful basis, retention and erasure rule, checked against the code (tables, files, caches), so GD-1 can
write one provider per row and GD-2 … GD-5 build on the same table. The contract that lets modules declare the same
things is ADR-0076 (scope of `module-interface` 0.6) and ADR-0077 (the privacy contract).

This ADR explains legal ideas in plain language to make engineering choices. It is not legal advice. Lawful bases
and retention values below are **suggestions** (stage-5 gap 4): the site owner decides, and the GD-9 reviewer
checks them before `1.0.0`.

## Decision

### Roles (D43, D51)

- Treat the **site owner** (from the site profile) as the **controller** of the site's personal data. A tenant
  operator who hosts sites for others is their **processor**; the docs ship a plain-language processing-agreement
  template. When the operator and the owner are the same organisation, it is simply the controller.
- Send nothing to the SightPress project: no telemetry, no phone-home, no update checks (D51). An update check, if
  ever added, is an off-by-default processor like any other.

### Inventory

- Describe every data set through a `PersonalDataProvider` (ADR-0077), core's own included. Core's modules use the
  same contract an external module would; the `PrivacyModule` (GD-1, required) collects them.
- Mark every entity field that holds personal data with `#[PersonalData]`; `composer check` fails on a marked field
  no provider covers, and on a module table with a user-id column but no provider (GD-1).
- Start from this table. Retention is the suggested default; *setting* means the site can change it within the
  tenant's limits (GD-2). Erasure is what GD-4 does to that data when a person is erased.

| Data set (provider key) | Where (class / table / file) | Personal data | Category | Purpose | Suggested lawful basis | Retention | Erasure |
|---|---|---|---|---|---|---|---|
| `user.account` — accounts and profiles | `User\Entity\User`, table `users` | email, first / last / display name, password hash, status, time zone, admin language, admin display preferences, created / updated dates | identity, contact, account | let the person sign in and work on the site | contract (Art. 6(1)(b)) | while the account exists; unverified sign-ups 7 days (GD-2) | row replaced by a tombstone (*Former user*, D46): email, names, password hash, time zone, language cleared |
| `user.avatar` — profile pictures | `User\Avatar\AvatarService`, site storage `avatars/`, columns `users.avatar_*` | uploaded picture or generated initials and colour | identity | show who did what in the admin | contract | while the account exists | file deleted, columns cleared |
| `user.phone` — mobile number and text consent | `User\Entity\User`, columns `users.phone_e164`, `phone_verified_at`, `phone_lookup`, `sms_consent_at`, `sms_opt_out_at`, `sms_notifications` (N-3) | mobile number, line type, consent and opt-out dates, chosen text notifications | contact, consent | phone sign-in, two-step confirmation, text notifications the person chose | consent (Art. 6(1)(a)) for notifications; contract for sign-in by text | while the account exists; consent proof as `consent_record` (GD-7) | cleared |
| `rbac.roles` — role assignments | `Rbac\Entity\RoleAssignment`, table `user_role` | user id → role | account | decide what the person may do | contract | while the account exists | rows deleted |
| `auth.sessions` — sign-in sessions | `Http\Session\FileSessionStore` (`session.path`, default `var/sessions/`), `Http\Session\SessionMiddleware` (cookie `sightpress_session`); H-9 adds the database and Redis stores | session id, user id, site id, password stamp, CSRF token, flash notices | account, technical | keep the person signed in; protect forms | contract; strictly necessary (no consent needed for the cookie) | the idle timeout | every session of the user deleted, in every store |
| `auth.remember` — "keep me signed in" | `Auth\RememberTokens`, table `auth_remember_tokens` (cookie `sightpress_remember`) | selector, hashed validator, user id, expiry | account, technical | sign the person in again on a device they chose | contract (they ticked it) | until it expires | rows deleted |
| `auth.codes` — verification codes | `Auth\VerificationCodeStore`, table `verification_code` | account id, keyed hash of the email or phone the code went to, code hash, attempts, dates | account, technical | check a 6-digit code (D30) | contract; legitimate interests (account security) | used or expired codes 1 day (GD-2) | rows deleted (by account id and by the hash of the email and phone) |
| `auth.throttle` — sign-in, code and form limits | `Http\RateLimit\RateLimiters`, cache `var/cache/rate_limits` (keys such as `id:<email>\|<ip>`, `account:<id>\|<ip>`, client address) | email or account id, IP address, attempt counts | technical | stop password guessing and abuse | legitimate interests (Art. 6(1)(f)) | the limit's window, at most 24 h (GD-2) | entries keyed by the user's id or email deleted |
| `people.invitations` — invitations | `People\Invitations`, table `invitations` | email, first / last name, role, who invited, dates | identity, contact | let the invited person join | legitimate interests (of the site, to invite); contract once accepted | expired invitations 30 days (GD-2) | rows for the person's email deleted; `invited_by` set to the tombstone |
| `audit.log` — audit log | `Audit\AuditLog`, `Audit\AuditRecorder`, table `audit_log` | actor id, subject id, summaries that name people (`AuditRecorder::person()` writes names), details | account, usage | show who changed what, for security and accountability | legitimate interests | entries 24 months, setting 6–84 (D45) | pseudonymised: actor and subject → tombstone, names in summaries and personal values in details → `[erased]`; history kept |
| `sms.log` — text message log | `Sms\SmsLog`, table `sms_message`; `sms_opt_out`; `sms_webhook_report` | user id, full number while queued (`to_e164`), masked number, keyed hash, body, status; opt-outs as a number hash only; webhook digests only | contact, communications | send, retry and prove delivery; honour STOP | contract / consent (as the message) | 90 days (GD-2); opt-out hashes while the opt-out stands (they stop texts to that number) | rows for the user and the number deleted; opt-out hash kept (it is what stops new texts) |
| `assistant.usage` — AI assistant usage | `Assistant\AssistantUsageStore`, table `assistant_usage` | user id, task, provider, model, token counts, outcome | usage | budget and limits per person (ADR-0040) | legitimate interests | the budget period plus 12 months (GD-2) | `user_id` set to the tombstone (counts stay for the budget) |
| `media.uploads` — who uploaded files | `Media\Entity\Media` (`media.uploaded_by`), table `media_upload` (`user_id`, `file_name`) | uploader id; names of files still uploading | account | show who added a file; resume an upload | contract | `media` while the file exists (it belongs to the site, D46); `media_upload` as M-8's cleanup | `uploaded_by` → tombstone; unfinished uploads deleted |
| `page.authors` — who wrote pages | `Page\Entity\Page` (`pages.author_id`, `scheduled_by`), `Page\Entity\PageRevision` (`page_revisions.saved_by`), `Module\SiteModuleStore` (`site_module.changed_by`) | user ids | account | show history and who to ask | legitimate interests | while the page exists (it belongs to the site, D46) | ids → tombstone; bylines and revisions say *Former user*; JSON-LD `author` left out (gap 11) |
| `site.profile` — the owner's contact details | `Site\Contract\SiteProfile`, table `site_settings` (`site.contact_email`, `site.phone`, `site.address`, `site.legal_name`) | the owner's contact details, published on purpose | contact | tell visitors who runs the site (and, in GD-5, who the controller is) | legitimate interests / legal obligation (who runs a site) | while the owner keeps them | the owner edits them; not tied to an account |
| `contact.form` — contact form messages | `Contact\ContactMailer`, `Contact\Http\ContactFormHandler` | name, email, message: **sent by email, never stored** (D47); the client address only as a rate-limit key | contact, communications | answer the visitor | legitimate interests | not stored (the mailbox is the owner's) | nothing to erase in SightPress |

- Write in the inventory, and in the generated policy, why these store **no** personal data:
  - **Analytics** (`Analytics\AnalyticsRecorder`, tables `analytics_hit`, `analytics_daily`, `analytics_salt`;
    ADR-0012): no IP, user agent or query string is stored, only a visitor key hashed with a salt that is deleted
    every day; signed-in people, `Sec-GPC` and `DNT` are never counted.
  - **Search** (`Search\SearchIndexer`, tables `search_document`, `search_word`, `search_posting`; SR-1): only
    published page text, never accounts; searches are counted by the analytics rules above. (Erasure still removes a
    name if an editor typed it into a page — that is content, D46.)
  - **Menus, redirects, page text, media files, settings** hold the site's content, not account data. Media files
    and page text may contain personal data the editors put there on purpose; they belong to the site (D46).
- Name every outbound flow as a processor, declared only while it is configured (ADR-0077): the mailer (host from
  `MAILER_DSN`), cloud storage and CDN, the SMS provider, the AI provider, IndexNow, and cloud storage provisioners.
  Everything goes through one `OutboundHttp` client that refuses a host no enabled processor declares (GD-1).

### Retention (D45)

- Run one `privacy:purge` job on `schedule:run` (batched, DBAL only, idempotent) for every retention above that no
  existing job already handles (analytics roll-up, upload cleanup, SMS outbox and search jobs stay where they are).
- Keep defaults and limits per tenant (`tenant.privacy.retention`), adjustable per site within the limits on
  *Settings → Privacy*, every change audited.
- Only the privacy jobs may change audit rows; each run is itself audited, with counts only. `audit_log` has no IP
  or user agent columns today; if they are ever added, they are cleared after 90 days (D45).

### Access and portability

- Build *Download your data* from the providers' `export()`: one ZIP with `data.json` and an accessible
  `data.html`, built in the background through a `privacy_request` table claimed by `schedule:run` (gap 5), stored
  privately for 7 days; the email says it is ready and has no link (D30).

### Erasure by tombstone (D44, D46)

- Deactivate an account at once and erase it after the grace period (7 days, tenant 0–30; signing in cancels).
  Admins and the CLI can erase at once.
- Erase by calling every provider's `erase()`, then replace the user row with a tombstone shown as *Former user*.
  Keep pages and media the person wrote (they belong to the site) and pseudonymise the audit log.
- Write an `erasure_log` row (user id + keyed hash of the email with `PRIVACY_ERASURE_KEY` and a key id, gap 7),
  never the email, so `site:privacy:reapply-erasures` can erase again after a backup restore.
- Refuse to erase the last admin.

### No third parties by default (D49, D50)

- Set only strictly-necessary cookies (`sightpress_session`, `sightpress_remember`, both `HttpOnly`, `SameSite=Lax`,
  `__Host-` over HTTPS), and **none** for anonymous visitors on a GET, including pages with a contact form (a
  stateless signed form token instead of a session, gap 3). So core needs no consent banner.
- Keep the nonce-based CSP that allows only the site's origin plus the configured CDN and storage host; themes
  bundle their fonts, scripts and styles; future remote embeds are click-to-load.
- Make a fresh site with default settings send **no** outbound request while rendering, searching, registering or
  sending a contact form, apart from its own mailer.
- Default cloud regions to the owner's jurisdiction (UK → London, EU/EEA/CH → an EU region); name any other region
  as an international transfer in the policy.

### Logs

- Keep personal data out of logs, exceptions, error pages and CLI output (as N-2's canary tests do).

## Consequences

- GD-1 writes one provider per table row above, with the provider keys given here, and the CI check keeps the table
  and the code in step: a new personal field or a user-id column without a provider fails `composer check`.
- The rate-limit cache holds emails and IP addresses as keys; GD-2/GD-4 must be able to find entries by user, so the
  limiter keys may need a stable, hashed form (decided in GD-1).
- Audit summaries hold names frozen at the time of writing, so erasure rewrites summaries as well as ids.
- The record of processing (GD-5) prints the suggested lawful bases with a "suggested — the site owner decides"
  label until the owner changes them.
- Reviewed by Pedro; the GD-9 reviewer, once found, reviews this table before `1.0.0`.
